<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Auth\EmailVerifyHandler;
use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\RegistrationThrottle;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(EmailVerifyHandler::class)]
#[CoversClass(RegistrationThrottle::class)]
#[CoversClass(UserRepository::class)]
final class EmailVerificationTest extends AuthTestCase
{
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->registeredAccount('immendingen', 'max@example.org', 'unverified');
        $this->pdo()->exec("UPDATE fe_users SET name = 'Max Mustermann' WHERE id = " . $this->user);
    }

    public function testValidLinkSetsPendingAndNotifiesActiveAdmins(): void
    {
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin', 'display_name' => 'Wehrführer']);
        $this->account('immendingen', 'gesperrt@example.org', ['role' => 'admin', 'locked_at' => self::CREATED_AT]);
        $this->account('immendingen', 'benutzer@example.org');
        $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);
        $token = $this->token($this->user);

        $response = $this->verify($token->value());

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['message' => EmailVerifyHandler::MESSAGE], self::json($response));
        $user = new UserRepository($this->pdo())->findById($this->user);
        self::assertSame(UserStatus::Pending, $user?->status);
        self::assertNotNull($user->emailVerifiedAt);
        self::assertSame(0, $this->countRows('fe_user_tokens'), 'Link eingelöst');

        // registeredAccount() legt einen aktiven Admin als Einlader an: zwei Empfänger.
        $recipients = array_map(static fn ($mail): string => $mail->to->value, $this->mailer->sent);
        sort($recipients);
        self::assertSame(['admin@example.org', 'einlader-unverified-max@example.org'], $recipients);
        $mail = array_values(array_filter($this->mailer->sent, static fn ($m): bool => $m->to->value === 'admin@example.org'))[0];
        self::assertSame('Neue Registrierung wartet auf Freischaltung', $mail->subject);
        self::assertStringStartsWith("Hallo Wehrführer,\n", $mail->text);
        self::assertStringContainsString("\nName: Max Mustermann\nE-Mail: max@example.org\n", $mail->text);
        self::assertStringContainsString("\n" . self::BASE_URL . "/firefighting/immendingen/benutzer\n", $mail->text, 'Link auf die Benutzerverwaltung');
        self::assertSame(1, $this->attempts(1));

        self::assertError(403, 'pending_approval', $this->login('immendingen', 'max@example.org'));
        self::assertError(400, 'invalid_token', $this->verify($token->value()), 'nur einmal nutzbar');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'falsches Format' => ['format'];
        yield 'unbekannt' => ['unbekannt'];
        yield 'anderer Zweck' => ['zweck'];
        yield 'abgelaufen' => ['abgelaufen'];
        yield 'schon bestätigt' => ['pending'];
        yield 'gesperrt' => ['gesperrt'];
        yield 'andere Stadt' => ['stadt'];
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidLinkIs400AndCounts(string $case): void
    {
        $tokens = new UserTokenRepository($this->pdo());
        $value = match ($case) {
            'format' => 'kaputt',
            'unbekannt' => OneTimeToken::generate()->value(),
            'zweck' => $tokens->replace($this->user, TokenPurpose::ResetPassword)->value(),
            'stadt' => $tokens->replace($this->registeredAccount('tuttlingen', 'max@example.org', 'unverified'), TokenPurpose::VerifyEmail)->value(),
            default => $this->token($this->user)->value(),
        };
        match ($case) {
            'abgelaufen' => $this->pdo()->exec('UPDATE fe_user_tokens SET created_at = created_at - INTERVAL 2 DAY, expires_at = expires_at - INTERVAL 2 DAY'),
            'pending' => $this->pdo()->exec("UPDATE fe_users SET status = 'pending', email_verified_at = UTC_TIMESTAMP() WHERE id = " . $this->user),
            'gesperrt' => $this->pdo()->exec('UPDATE fe_users SET locked_at = UTC_TIMESTAMP() WHERE id = ' . $this->user),
            default => null,
        };

        $response = $this->verify($value);

        self::assertError(400, 'invalid_token', $response);
        self::assertSame(1, $this->attempts(0));
        self::assertSame([], $this->mailer->sent);
        if ($case !== 'pending') {
            self::assertSame(UserStatus::Unverified, new UserRepository($this->pdo())->findById($this->user)?->status);
        }
    }

    public function testFailuresAreThrottledPerIp(): void
    {
        for ($i = 0; $i < RegistrationThrottle::MAX_VERIFY_FAILURES_PER_IP; ++$i) {
            self::assertError(400, 'invalid_token', $this->verify(OneTimeToken::generate()->value()));
        }

        $response = $this->verify($this->token($this->user)->value());

        self::assertError(429, 'too_many_attempts', $response);
        self::assertGreaterThan(RegistrationThrottle::VERIFY_WINDOW_SECONDS - 60, (int) $response->headers()['Retry-After']);
        self::assertSame(RegistrationThrottle::MAX_VERIFY_FAILURES_PER_IP, $this->attempts(0));
        self::assertSame(UserStatus::Unverified, new UserRepository($this->pdo())->findById($this->user)?->status);
    }

    public function testOnlyUnderTenantAndTokenRequired(): void
    {
        self::assertError(404, 'not_found', $this->send('POST', self::url(null, 'auth/email/verify'), ['token' => 'x']));
        self::assertError(400, 'invalid_request', $this->send('POST', self::url('immendingen', 'auth/email/verify'), []));
    }

    public function testStaleRegistrationCannotBeVerified(): void
    {
        $token = $this->token($this->user);
        $this->pdo()->exec('UPDATE fe_users SET created_at = created_at - INTERVAL 8 DAY WHERE id = ' . $this->user);

        self::assertError(400, 'invalid_token', $this->verify($token->value()));
        self::assertSame(UserStatus::Deleted, new UserRepository($this->pdo())->findById($this->user)?->status);
    }

    public function testMailFailureToAdminIsLoggedOnly(): void
    {
        $this->mailer->fail = true;

        $response = $this->verify($this->token($this->user)->value());

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertCount(1, $this->log);
        self::assertStringStartsWith('firefighting: mail_failed (registration_pending, Konto #', $this->log[0]);
    }

    private function token(int $userId): OneTimeToken
    {
        return new UserTokenRepository($this->pdo())->replace($userId, TokenPurpose::VerifyEmail);
    }

    private function verify(string $token): JsonResponse
    {
        return $this->send('POST', self::url('immendingen', 'auth/email/verify'), ['token' => $token]);
    }

    private function attempts(int $succeeded): int
    {
        return (int) $this->pdo()->query("SELECT COUNT(*) FROM fe_auth_attempts WHERE action = 'verify_email' AND succeeded = " . $succeeded)->fetchColumn();
    }
}
