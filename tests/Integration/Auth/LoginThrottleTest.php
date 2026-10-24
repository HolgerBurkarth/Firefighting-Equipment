<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Auth\IpPrefix;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\Auth\TooManyAttemptsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(LoginThrottle::class)]
#[CoversClass(TooManyAttemptsException::class)]
#[UsesClass(IpPrefix::class)]
final class LoginThrottleTest extends AuthTestCase
{
    private const string EMAIL = 'user@example.org';
    private const string WRONG = 'Falsches-Passwort-99';

    protected function setUp(): void
    {
        parent::setUp();

        $this->account('immendingen', self::EMAIL);
    }

    public function testFifthFailureBlocksEvenTheCorrectPassword(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ACCOUNT; $i++) {
            self::assertError(401, 'invalid_credentials', $this->login('immendingen', self::EMAIL, self::WRONG));
        }

        $response = $this->login('immendingen', self::EMAIL);

        self::assertError(429, 'too_many_attempts', $response);
        $retryAfter = (int) $response->headers()['Retry-After'];
        self::assertGreaterThan(LoginThrottle::WINDOW_SECONDS - 30, $retryAfter);
        self::assertLessThanOrEqual(LoginThrottle::WINDOW_SECONDS, $retryAfter);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
        self::assertSame(0, $this->countRows('fe_sessions'));
    }

    public function testBlockedAttemptsAreNotRecorded(): void
    {
        $this->failures($this->tenantId(), self::EMAIL, '192.0.2.1', LoginThrottle::MAX_FAILURES_PER_ACCOUNT, 60);
        $before = $this->countRows('fe_auth_attempts');

        self::assertError(429, 'too_many_attempts', $this->login('immendingen', self::EMAIL, self::WRONG));
        self::assertError(429, 'too_many_attempts', $this->login('immendingen', self::EMAIL));

        self::assertSame($before, $this->countRows('fe_auth_attempts'));
    }

    public function testRetryAfterIsTimeUntilOldestCountedFailureLeavesWindow(): void
    {
        $this->failures($this->tenantId(), self::EMAIL, '198.51.100.1', 1, 700);
        $this->failures($this->tenantId(), self::EMAIL, '198.51.100.1', LoginThrottle::MAX_FAILURES_PER_ACCOUNT - 1, 600);

        $response = $this->login('immendingen', self::EMAIL);

        self::assertError(429, 'too_many_attempts', $response);
        self::assertEqualsWithDelta(LoginThrottle::WINDOW_SECONDS - 700, (int) $response->headers()['Retry-After'], 2);
    }

    public function testFailuresOutsideWindowDoNotCount(): void
    {
        $this->failures($this->tenantId(), self::EMAIL, '192.0.2.1', 10, LoginThrottle::WINDOW_SECONDS + 1);

        self::assertSame(200, $this->login('immendingen', self::EMAIL)->statusCode());
    }

    public function testSuccessResetsAccountCounter(): void
    {
        $this->failures($this->tenantId(), self::EMAIL, '192.0.2.1', LoginThrottle::MAX_FAILURES_PER_ACCOUNT - 1, 120);
        self::assertSame(200, $this->login('immendingen', self::EMAIL)->statusCode());
        $this->failures($this->tenantId(), self::EMAIL, '192.0.2.1', LoginThrottle::MAX_FAILURES_PER_ACCOUNT - 1, 0);

        self::assertSame(200, $this->login('immendingen', self::EMAIL)->statusCode());
    }

    public function testAccountCounterIsPerTenantAndService(): void
    {
        $this->account('tuttlingen', self::EMAIL);
        $this->account(null, self::EMAIL);
        $this->failures($this->tenantId(), self::EMAIL, '192.0.2.1', LoginThrottle::MAX_FAILURES_PER_ACCOUNT, 60);

        self::assertError(429, 'too_many_attempts', $this->login('immendingen', self::EMAIL));
        self::assertSame(200, $this->login('tuttlingen', self::EMAIL)->statusCode());
        self::assertSame(200, $this->login(null, self::EMAIL)->statusCode());
    }

    public function testUnknownEmailIsThrottledToo(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ACCOUNT; $i++) {
            $this->login('immendingen', 'niemand@example.org');
        }

        self::assertError(429, 'too_many_attempts', $this->login('immendingen', 'niemand@example.org'));
    }

    public function testIpPrefixLimitAcrossAccountsAndTenants(): void
    {
        // Gleiches /24 wie self::IP, verteilt über viele Adressen und beide Städte.
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_IP; $i++) {
            $tenantId = $i % 2 === 0 ? $this->tenantId() : $this->tenant('tuttlingen')->id;
            $this->failures($tenantId, sprintf('raten%d@example.org', $i), '192.0.2.' . (100 + $i), 1, 60);
        }

        $response = $this->login('immendingen', self::EMAIL);

        self::assertError(429, 'too_many_attempts', $response);
        self::assertEqualsWithDelta(LoginThrottle::WINDOW_SECONDS - 60, (int) $response->headers()['Retry-After'], 2);
    }

    public function testIpLimitIsNotResetBySuccessAndOtherPrefixIsFree(): void
    {
        $this->failures($this->tenantId(), 'raten@example.org', '192.0.2.50', LoginThrottle::MAX_FAILURES_PER_IP - 1, 60);
        $this->insertAttempt($this->tenantId(), 'erfolg@example.org', '192.0.2.51', true, 30);

        // Noch ein Fehlversuch bis zur Grenze, dann gesperrt – trotz des Erfolgs.
        self::assertError(401, 'invalid_credentials', $this->login('immendingen', 'neu@example.org'));
        self::assertError(429, 'too_many_attempts', $this->login('immendingen', self::EMAIL));

        $throttle = new LoginThrottle($this->pdo());
        self::assertNull($throttle->retryAfter($this->tenantId(), LoginThrottle::emailHash(self::EMAIL), IpPrefix::fromAddress('203.0.113.9')));
    }

    public function testIpv6PrefixIs48Bits(): void
    {
        $this->failures($this->tenantId(), 'raten@example.org', '2001:db8:1:aaaa::1', LoginThrottle::MAX_FAILURES_PER_IP, 60);
        $throttle = new LoginThrottle($this->pdo());
        $email = LoginThrottle::emailHash(self::EMAIL);

        self::assertNotNull($throttle->retryAfter($this->tenantId(), $email, IpPrefix::fromAddress('2001:db8:1:bbbb::2')));
        self::assertNull($throttle->retryAfter($this->tenantId(), $email, IpPrefix::fromAddress('2001:db8:2::1')));
    }

    public function testDeleteOldRemovesEntriesOlderThan24Hours(): void
    {
        $this->insertAttempt($this->tenantId(), 'a@example.org', '192.0.2.1', false, 24 * 3600 + 60);
        $this->insertAttempt($this->tenantId(), 'b@example.org', '192.0.2.1', true, 23 * 3600);

        self::assertSame(1, new LoginThrottle($this->pdo())->deleteOld());
        self::assertSame(1, $this->countRows('fe_auth_attempts'));
    }

    private function tenantId(): int
    {
        return $this->tenant('immendingen')->id;
    }

    private function failures(?int $tenantId, string $email, string $ip, int $count, int $secondsAgo): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->insertAttempt($tenantId, $email, $ip, false, $secondsAgo);
        }
    }

    private function insertAttempt(?int $tenantId, string $email, string $ip, bool $succeeded, int $secondsAgo): void
    {
        $this->pdo()->prepare(
            'INSERT INTO fe_auth_attempts (action, tenant_id, email_hash, ip_prefix, succeeded, attempted_at)'
            . " VALUES ('login', ?, ?, ?, ?, UTC_TIMESTAMP() - INTERVAL ? SECOND)",
        )->execute([$tenantId, LoginThrottle::emailHash($email), IpPrefix::fromAddress($ip), $succeeded ? 1 : 0, $secondsAgo]);
    }
}
