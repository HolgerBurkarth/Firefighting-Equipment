<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Auth\PasswordResetRequestHandler;
use FirefightingEquipment\Auth\PasswordResetThrottle;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\MinimumDuration;
use FirefightingEquipment\Http\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(PasswordResetRequestHandler::class)]
#[CoversClass(PasswordResetThrottle::class)]
#[CoversClass(UserTokenRepository::class)]
final class PasswordResetRequestTest extends AuthTestCase
{
    public function testActiveAccountGetsMailWithLink(): void
    {
        $id = $this->account('immendingen', 'user@example.org');

        $response = $this->request('immendingen', 'User@Example.org');

        self::assertNeutral($response);
        $mail = $this->mailer->only();
        self::assertSame('user@example.org', $mail->to->value);
        self::assertSame('Passwort zurücksetzen', $mail->subject);
        $token = self::tokenFrom($mail->text, 'immendingen');
        self::assertSame(hash('sha256', (string) base64_decode(strtr($token, '-_', '+/'), true), true), $this->tokenHashOf($id));
        self::assertSame([], $this->log);
    }

    public function testSuperAdminGetsLinkUnderService(): void
    {
        $this->account(null, 'chef@example.org');

        self::assertNeutral($this->request(null, 'chef@example.org'));

        self::tokenFrom($this->mailer->only()->text, 'service');
    }

    public function testNewLinkReplacesOldOne(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $this->request('immendingen', 'user@example.org');
        $first = $this->tokenHashOf($id);

        $this->request('immendingen', 'user@example.org');

        self::assertCount(2, $this->mailer->sent);
        self::assertNotSame($first, $this->tokenHashOf($id));
        self::assertSame(1, $this->countRows('fe_user_tokens'));
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function accountsWithoutMail(): iterable
    {
        yield 'unbekannte Adresse' => ['immendingen', 'niemand@example.org'];
        yield 'Konto einer anderen Stadt' => ['tuttlingen', 'user@example.org'];
        yield 'Super-Admin unter einer Stadt' => ['immendingen', 'chef@example.org'];
        yield 'Stadt-Konto unter service' => [null, 'user@example.org'];
        yield 'gesperrt' => ['immendingen', 'gesperrt@example.org'];
        yield 'eingeladen' => ['immendingen', 'eingeladen@example.org'];
        yield 'E-Mail unbestätigt' => ['immendingen', 'unbestaetigt@example.org'];
        yield 'wartet auf Freischaltung' => ['immendingen', 'wartend@example.org'];
    }

    /**
     * Gleiche Antwort (Status, Body, Kopfzeilen) wie für ein aktives Konto, aber keine Mail.
     */
    #[DataProvider('accountsWithoutMail')]
    public function testSameResponseButNoMail(?string $slug, string $email): void
    {
        $this->createAccounts();
        $reference = $this->request('immendingen', 'user@example.org');
        self::assertCount(1, $this->mailer->sent);

        $response = $this->request($slug, $email);

        self::assertSame($reference->statusCode(), $response->statusCode());
        self::assertSame($reference->body(), $response->body());
        self::assertSame($reference->headers(), $response->headers());
        self::assertCount(1, $this->mailer->sent, 'keine weitere Mail');
        self::assertSame(1, $this->countRows('fe_user_tokens'), 'kein weiterer Link');
    }

    public function testDeletedAccountGetsNoMail(): void
    {
        $id = $this->account('immendingen', 'weg@example.org');
        $this->pdo()->prepare(
            "UPDATE fe_users SET status = 'deleted', email = NULL, email_verified_at = NULL, password_hash = NULL,"
            . ' name = NULL, display_name = NULL, deleted_at = UTC_TIMESTAMP() WHERE id = ?',
        )->execute([$id]);

        self::assertNeutral($this->request('immendingen', 'weg@example.org'));
        self::assertSame([], $this->mailer->sent);
    }

    public function testResponseTakesAtLeastTheMinimumDurationForKnownAndUnknownAddresses(): void
    {
        $this->account('immendingen', 'user@example.org');
        $app = $this->app(tenant: Routes::tenant(new MinimumDuration(0.3)));

        foreach (['user@example.org', 'niemand@example.org'] as $email) {
            $start = hrtime(true);
            $response = $this->send('POST', self::url('immendingen', 'auth/password-reset/request'), ['email' => $email], app: $app);
            $seconds = (hrtime(true) - $start) / 1e9;

            self::assertNeutral($response);
            self::assertGreaterThanOrEqual(0.3, $seconds, $email);
            self::assertLessThan(1.5, $seconds, $email);
        }
        self::assertCount(1, $this->mailer->sent);
    }

    public function testDefaultMinimumDurationIsOneAndAHalfSeconds(): void
    {
        $duration = new \ReflectionProperty(PasswordResetRequestHandler::class, 'duration')->getValue(new PasswordResetRequestHandler());

        self::assertSame(1.5, $duration->seconds);
    }

    public function testOverrunOfMinimumDurationIsLogged(): void
    {
        $now = 0.0;
        // Jeder Blick auf die Uhr kostet 2 s: Die Mindestdauer ist beim Prüfen schon überschritten.
        $clock = new MinimumDuration(1.5, static function () use (&$now): float {
            $now += 2.0;

            return $now;
        }, static function (): void {
        });

        self::assertNeutral($this->send('POST', self::url('immendingen', 'auth/password-reset/request'), ['email' => 'a@example.org'], app: $this->app(tenant: Routes::tenant($clock))));

        self::assertCount(1, $this->log);
        self::assertStringContainsString('password_reset_slow', $this->log[0]);
    }

    public function testMailFailureStillGivesSameResponseAndLogsWithoutToken(): void
    {
        $this->account('immendingen', 'user@example.org');
        $this->mailer->fail = true;

        self::assertNeutral($this->request('immendingen', 'user@example.org'));

        self::assertCount(1, $this->log);
        self::assertStringContainsString('mail_failed (password_reset, Konto #', $this->log[0]);
        self::assertStringNotContainsString('user@example.org', $this->log[0]);
        self::assertStringNotContainsString('token', strtolower($this->log[0]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'ohne E-Mail' => [[], 'Das Feld "email" fehlt'];
        yield 'E-Mail kein Text' => [['email' => 42], 'Das Feld "email" fehlt'];
        yield 'ungültige Schreibweise' => [['email' => 'user@example'], 'Bitte eine gültige E-Mail-Adresse angeben.'];
        yield 'leer' => [['email' => ' '], 'Bitte eine gültige E-Mail-Adresse angeben.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIs400(array $body, string $message): void
    {
        $response = $this->send('POST', self::url('immendingen', 'auth/password-reset/request'), $body);

        self::assertError(400, 'invalid_request', $response);
        self::assertStringContainsString($message, self::json($response)['message']);
        self::assertSame(0, $this->countRows('fe_auth_attempts'), 'zählt nicht in die Bremse');
    }

    public function testThrottlePerAddressAppliesEquallyToUnknownAddresses(): void
    {
        $this->account('immendingen', 'user@example.org');

        foreach (['user@example.org', 'niemand@example.org'] as $email) {
            for ($i = 0; $i < PasswordResetThrottle::MAX_REQUESTS_PER_ACCOUNT; $i++) {
                self::assertNeutral($this->request('immendingen', $email));
            }
            $response = $this->request('immendingen', $email);

            self::assertError(429, 'too_many_attempts', $response);
            $retryAfter = (int) $response->headers()['Retry-After'];
            self::assertGreaterThan(3500, $retryAfter, $email);
            self::assertLessThanOrEqual(3600, $retryAfter, $email);
        }
        self::assertCount(3, $this->mailer->sent);
        self::assertSame(6, $this->countRows('fe_auth_attempts'), 'Abgewiesene Anfragen werden nicht gespeichert');
    }

    public function testThrottlePerAddressIsPerArea(): void
    {
        for ($i = 0; $i < PasswordResetThrottle::MAX_REQUESTS_PER_ACCOUNT; $i++) {
            $this->request('immendingen', 'user@example.org');
        }

        self::assertError(429, 'too_many_attempts', $this->request('immendingen', 'user@example.org'));
        self::assertNeutral($this->request('tuttlingen', 'user@example.org'));
        self::assertNeutral($this->request(null, 'user@example.org'));
    }

    public function testThrottlePerIpCoversAllAreas(): void
    {
        for ($i = 0; $i < PasswordResetThrottle::MAX_REQUESTS_PER_IP; $i++) {
            self::assertNeutral($this->request($i % 2 === 0 ? 'immendingen' : 'tuttlingen', sprintf('person%d@example.org', $i)));
        }

        self::assertError(429, 'too_many_attempts', $this->request(null, 'neu@example.org'));
    }

    public function testOldRequestsLeaveTheWindow(): void
    {
        for ($i = 0; $i < PasswordResetThrottle::MAX_REQUESTS_PER_ACCOUNT; $i++) {
            $this->request('immendingen', 'user@example.org');
        }
        $this->pdo()->exec('UPDATE fe_auth_attempts SET attempted_at = attempted_at - INTERVAL 3601 SECOND');

        self::assertNeutral($this->request('immendingen', 'user@example.org'));
    }

    public function testExpiredLinksAndOldAttemptsAreCleanedUp(): void
    {
        $id = $this->account('immendingen', 'alt@example.org');
        $this->insert('fe_user_tokens', [
            'user_id' => $id,
            'purpose' => 'reset_password',
            'token_hash' => self::hash('alt'),
            'created_at' => '2026-01-01 10:00:00',
            'expires_at' => '2026-01-01 11:00:00',
        ]);
        $this->pdo()->exec(
            "INSERT INTO fe_auth_attempts (action, tenant_id, email_hash, ip_prefix, succeeded, attempted_at)"
            . " VALUES ('password_reset', NULL, UNHEX(SHA2('x', 256)), X'C0000200', 1, UTC_TIMESTAMP() - INTERVAL 25 HOUR)",
        );

        $this->request('immendingen', 'niemand@example.org');

        self::assertSame(0, $this->countRows('fe_user_tokens'));
        self::assertSame(1, $this->countRows('fe_auth_attempts'));
    }

    private function request(?string $slug, string $email): JsonResponse
    {
        return $this->send('POST', self::url($slug, 'auth/password-reset/request'), ['email' => $email]);
    }

    private static function assertNeutral(JsonResponse $response): void
    {
        self::assertSame(202, $response->statusCode(), $response->body());
        self::assertSame(['message' => PasswordResetRequestHandler::MESSAGE], self::json($response));
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
    }

    /**
     * Token aus dem Link der Mail (prüft dabei die Form des Links).
     */
    private static function tokenFrom(string $text, string $area): string
    {
        $pattern = '#^' . preg_quote(self::BASE_URL . '/firefighting/' . $area . '/passwort-zuruecksetzen#token=', '#') . '([A-Za-z0-9_-]{43})$#m';
        self::assertSame(1, preg_match($pattern, $text, $m), $text);

        return $m[1];
    }

    private function tokenHashOf(int $userId): string
    {
        $stmt = $this->pdo()->prepare("SELECT token_hash FROM fe_user_tokens WHERE user_id = ? AND purpose = 'reset_password'");
        $stmt->execute([$userId]);

        return (string) $stmt->fetchColumn();
    }

    private function createAccounts(): void
    {
        $this->account('immendingen', 'user@example.org');
        $this->account(null, 'chef@example.org');
        $this->account('immendingen', 'gesperrt@example.org', ['locked_at' => self::CREATED_AT]);
        $this->account('immendingen', 'eingeladen@example.org', [
            'role' => 'admin', 'status' => 'invited', 'password_hash' => null, 'name' => null, 'display_name' => null, 'email_verified_at' => null,
        ]);
        $this->registeredAccount('immendingen', 'unbestaetigt@example.org', 'unverified');
        $this->registeredAccount('immendingen', 'wartend@example.org', 'pending');
    }
}
