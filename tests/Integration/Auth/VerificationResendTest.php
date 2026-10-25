<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Auth\EmailResendHandler;
use FirefightingEquipment\Auth\RegistrationThrottle;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\MinimumDuration;
use FirefightingEquipment\Http\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(EmailResendHandler::class)]
#[CoversClass(RegistrationThrottle::class)]
final class VerificationResendTest extends AuthTestCase
{
    public function testUnverifiedAccountGetsNewLinkReplacingOldOne(): void
    {
        $id = $this->registeredAccount('immendingen', 'max@example.org', 'unverified');

        self::assertNeutral($this->resend('Max@Example.org'));
        $first = $this->tokenHash($id);
        self::assertNeutral($this->resend('max@example.org'));

        self::assertCount(2, $this->mailer->sent);
        $mail = $this->mailer->sent[1];
        self::assertSame('max@example.org', $mail->to->value);
        self::assertSame('Bitte bestätigen Sie Ihre E-Mail-Adresse', $mail->subject);
        self::assertStringContainsString(self::BASE_URL . '/firefighting/immendingen/email-bestaetigen#token=', $mail->text);
        self::assertNotSame($first, $this->tokenHash($id));
        self::assertSame(1, $this->countRows('fe_user_tokens'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function withoutMail(): iterable
    {
        yield 'unbekannt' => ['immendingen', 'niemand@example.org'];
        yield 'aktiv' => ['immendingen', 'aktiv@example.org'];
        yield 'wartet auf Freischaltung' => ['immendingen', 'wartet@example.org'];
        yield 'gesperrt' => ['immendingen', 'gesperrt@example.org'];
        yield 'andere Stadt' => ['tuttlingen', 'max@example.org'];
    }

    #[DataProvider('withoutMail')]
    public function testSameResponseButNoMail(string $slug, string $email): void
    {
        $this->registeredAccount('immendingen', 'max@example.org', 'unverified');
        $this->account('immendingen', 'aktiv@example.org');
        $this->registeredAccount('immendingen', 'wartet@example.org', 'pending');
        $locked = $this->registeredAccount('immendingen', 'gesperrt@example.org', 'unverified');
        $this->pdo()->exec('UPDATE fe_users SET locked_at = UTC_TIMESTAMP() WHERE id = ' . $locked);
        $reference = $this->resend('max@example.org');
        $this->mailer->sent = [];

        $response = $this->resend($email, $slug);

        self::assertSame($reference->statusCode(), $response->statusCode());
        self::assertSame($reference->body(), $response->body());
        self::assertSame($reference->headers(), $response->headers());
        self::assertSame([], $this->mailer->sent);
    }

    public function testThrottledPerEmailAndTenant(): void
    {
        $this->registeredAccount('immendingen', 'max@example.org', 'unverified');
        for ($i = 0; $i < RegistrationThrottle::MAX_RESENDS_PER_ACCOUNT; ++$i) {
            self::assertNeutral($this->resend('max@example.org'));
        }

        $response = $this->resend('max@example.org');

        self::assertError(429, 'too_many_attempts', $response);
        self::assertGreaterThan(RegistrationThrottle::RESEND_WINDOW_SECONDS - 60, (int) $response->headers()['Retry-After']);
        self::assertCount(RegistrationThrottle::MAX_RESENDS_PER_ACCOUNT, $this->mailer->sent);
        self::assertSame(RegistrationThrottle::MAX_RESENDS_PER_ACCOUNT, $this->countRows('fe_auth_attempts'));
        self::assertNeutral($this->resend('max@example.org', 'tuttlingen'), 'andere Stadt zählt getrennt');
    }

    public function testThrottledPerIpAlsoForUnknownAddresses(): void
    {
        for ($i = 0; $i < RegistrationThrottle::MAX_RESENDS_PER_IP; ++$i) {
            self::assertNeutral($this->resend("n{$i}@example.org", $i % 2 === 0 ? 'immendingen' : 'tuttlingen'));
        }

        self::assertError(429, 'too_many_attempts', $this->resend('neu@example.org'));
    }

    public function testInvalidEmailAndService(): void
    {
        self::assertError(400, 'invalid_request', $this->resend('kaputt'));
        self::assertError(404, 'not_found', $this->send('POST', self::url(null, 'auth/email/resend'), ['email' => 'max@example.org']));
        self::assertSame(0, $this->countRows('fe_auth_attempts'));
    }

    public function testResponseTakesAtLeastTheMinimumDuration(): void
    {
        $app = $this->app(tenant: Routes::tenant(new MinimumDuration(0.3)));
        $start = hrtime(true);

        self::assertNeutral($this->send('POST', self::url('immendingen', 'auth/email/resend'), ['email' => 'niemand@example.org'], app: $app));

        self::assertGreaterThanOrEqual(0.3, (hrtime(true) - $start) / 1e9);
        self::assertSame(1.5, EmailResendHandler::MIN_DURATION_SECONDS);
    }

    private function resend(string $email, string $slug = 'immendingen'): JsonResponse
    {
        return $this->send('POST', self::url($slug, 'auth/email/resend'), ['email' => $email]);
    }

    private function tokenHash(int $userId): string|false
    {
        $stmt = $this->pdo()->prepare("SELECT token_hash FROM fe_user_tokens WHERE user_id = ? AND purpose = 'verify_email'");
        $stmt->execute([$userId]);

        return $stmt->fetchColumn();
    }

    private static function assertNeutral(JsonResponse $response, string $message = ''): void
    {
        self::assertSame(202, $response->statusCode(), $message . ' ' . $response->body());
        self::assertSame(['message' => EmailResendHandler::MESSAGE], self::json($response));
    }
}
