<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Auth\PasswordResetConfirmHandler;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\PasswordResetThrottle;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\User\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(PasswordResetConfirmHandler::class)]
#[CoversClass(PasswordResetThrottle::class)]
#[CoversClass(UserTokenRepository::class)]
#[CoversClass(UserRepository::class)]
final class PasswordResetConfirmTest extends AuthTestCase
{
    private const string NEW_PASSWORD = 'Schlauch-Leiter-Wasser-7';

    public function testResetSetsPasswordEndsSessionsAndDeletesLink(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');
        $this->loginCookie('immendingen', 'user@example.org');
        $token = $this->requestToken('immendingen', 'user@example.org');
        $this->mailer->sent = [];

        $response = $this->confirm('immendingen', $token, self::NEW_PASSWORD, $cookie);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['message' => PasswordResetConfirmHandler::MESSAGE], self::json($response));
        self::assertSame('', self::cookieValue($response), 'mitgesendetes Cookie wird gelöscht');
        self::assertSame(0, $this->sessionCountOf($id), 'alle Sitzungen beendet, keine neue');
        self::assertSame(0, $this->countRows('fe_user_tokens'), 'Link eingelöst');
        self::assertError(401, 'invalid_credentials', $this->login('immendingen', 'user@example.org'));
        self::assertSame(200, $this->login('immendingen', 'user@example.org', self::NEW_PASSWORD)->statusCode());
    }

    public function testNotificationIsSentAfterReset(): void
    {
        $this->account('immendingen', 'user@example.org');
        $token = $this->requestToken('immendingen', 'user@example.org');
        $this->mailer->sent = [];

        self::assertSame(200, $this->confirm('immendingen', $token, self::NEW_PASSWORD)->statusCode());

        $mail = $this->mailer->only();
        self::assertSame('user@example.org', $mail->to->value);
        self::assertSame('Ihr Passwort wurde geändert', $mail->subject);
        self::assertStringContainsString("\n" . self::BASE_URL . "/firefighting/immendingen/\n", $mail->text);
        self::assertStringNotContainsString('Gerät:', $mail->text);
    }

    public function testWithoutCookieNoCookieIsCleared(): void
    {
        $this->account('immendingen', 'user@example.org');

        $response = $this->confirm('immendingen', $this->requestToken('immendingen', 'user@example.org'), self::NEW_PASSWORD);

        self::assertSame(200, $response->statusCode());
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
    }

    public function testSuperAdminResetsUnderService(): void
    {
        $this->account(null, 'chef@example.org');
        $token = $this->requestToken(null, 'chef@example.org');

        self::assertSame(200, $this->confirm(null, $token, self::NEW_PASSWORD)->statusCode());
        self::assertSame(200, $this->login(null, 'chef@example.org', self::NEW_PASSWORD)->statusCode());
    }

    public function testUsedLinkIsRejected(): void
    {
        $this->account('immendingen', 'user@example.org');
        $token = $this->requestToken('immendingen', 'user@example.org');
        self::assertSame(200, $this->confirm('immendingen', $token, self::NEW_PASSWORD)->statusCode());

        self::assertError(400, 'invalid_token', $this->confirm('immendingen', $token, 'Noch-ein-anderes-Passwort-8'));
        self::assertSame(200, $this->login('immendingen', 'user@example.org', self::NEW_PASSWORD)->statusCode());
    }

    public function testExpiredLinkIsRejectedAndDeleted(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $token = $this->requestToken('immendingen', 'user@example.org');
        $this->pdo()->exec('UPDATE fe_user_tokens SET created_at = created_at - INTERVAL 3601 SECOND, expires_at = expires_at - INTERVAL 3601 SECOND');
        $hash = $this->hashOf($id);

        self::assertError(400, 'invalid_token', $this->confirm('immendingen', $token, self::NEW_PASSWORD));
        self::assertSame($hash, $this->hashOf($id));
        self::assertSame(0, $this->countRows('fe_user_tokens'));
    }

    public function testLinkJustBeforeExpiryIsAccepted(): void
    {
        $this->account('immendingen', 'user@example.org');
        $token = $this->requestToken('immendingen', 'user@example.org');
        $this->pdo()->exec('UPDATE fe_user_tokens SET created_at = created_at - INTERVAL 3540 SECOND, expires_at = expires_at - INTERVAL 3540 SECOND');

        self::assertSame(200, $this->confirm('immendingen', $token, self::NEW_PASSWORD)->statusCode());
    }

    public function testLinkForOtherPurposeIsRejected(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $token = new UserTokenRepository($this->pdo())->replace($id, TokenPurpose::SetPassword);

        self::assertError(400, 'invalid_token', $this->confirm('immendingen', $token->value(), self::NEW_PASSWORD));
        self::assertSame(1, $this->countRows('fe_user_tokens'), 'fremder Link bleibt unangetastet');
    }

    /**
     * @return iterable<string, array{string|null, string, string|null}>
     */
    public static function wrongAreas(): iterable
    {
        yield 'Link einer anderen Stadt' => ['immendingen', 'user@example.org', 'tuttlingen'];
        yield 'Stadt-Link unter service' => ['immendingen', 'user@example.org', null];
        yield 'Super-Admin-Link unter einer Stadt' => [null, 'chef@example.org', 'immendingen'];
    }

    #[DataProvider('wrongAreas')]
    public function testLinkOfOtherAreaIsRejected(?string $slug, string $email, ?string $usedUnder): void
    {
        $this->account($slug, $email);
        $token = $this->requestToken($slug, $email);

        self::assertError(400, 'invalid_token', $this->confirm($usedUnder, $token, self::NEW_PASSWORD));
        self::assertSame(1, $this->countRows('fe_user_tokens'), 'Link bleibt für den richtigen Bereich gültig');
        self::assertSame(200, $this->confirm($slug, $token, self::NEW_PASSWORD)->statusCode());
    }

    public function testAccountLockedAfterRequestCannotReset(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $token = $this->requestToken('immendingen', 'user@example.org');
        $this->pdo()->prepare('UPDATE fe_users SET locked_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
        $hash = $this->hashOf($id);

        self::assertError(400, 'invalid_token', $this->confirm('immendingen', $token, self::NEW_PASSWORD));
        self::assertSame($hash, $this->hashOf($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'leer' => [''];
        yield 'zu kurz' => ['abc'];
        yield 'unbekannt' => [str_repeat('A', 43)];
        yield 'mit Auffüllung' => [str_repeat('A', 43) . '='];
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedOrUnknownTokenIsRejectedAndCounted(string $token): void
    {
        self::assertError(400, 'invalid_token', $this->confirm('immendingen', $token, self::NEW_PASSWORD));

        self::assertSame(1, (int) $this->pdo()->query(
            "SELECT COUNT(*) FROM fe_auth_attempts WHERE action = 'reset_confirm' AND succeeded = 0 AND email_hash IS NULL",
        )->fetchColumn());
    }

    public function testWeakPasswordKeepsLinkValid(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $token = $this->requestToken('immendingen', 'user@example.org');
        $hash = $this->hashOf($id);
        $this->mailer->sent = [];

        $response = $this->confirm('immendingen', $token, 'Musterfrau-2026');

        self::assertError(422, 'weak_password', $response);
        self::assertStringContainsString('Teile der E-Mail-Adresse, des Namens', self::json($response)['message']);
        self::assertSame($hash, $this->hashOf($id));
        self::assertSame(1, $this->countRows('fe_user_tokens'));
        self::assertSame([], $this->mailer->sent, 'keine Benachrichtigung');
        self::assertSame(200, $this->confirm('immendingen', $token, self::NEW_PASSWORD)->statusCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'ohne Token' => [['new_password' => self::NEW_PASSWORD]];
        yield 'ohne Passwort' => [['token' => str_repeat('A', 43)]];
        yield 'Token kein Text' => [['token' => 42, 'new_password' => self::NEW_PASSWORD]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIs400(array $body): void
    {
        self::assertError(400, 'invalid_request', $this->send('POST', self::url('immendingen', 'auth/password-reset/confirm'), $body));
        self::assertSame(0, $this->countRows('fe_auth_attempts'));
    }

    public function testFailuresAreThrottledPerIp(): void
    {
        $this->account('immendingen', 'user@example.org');
        $token = $this->requestToken('immendingen', 'user@example.org');
        for ($i = 0; $i < PasswordResetThrottle::MAX_CONFIRM_FAILURES_PER_IP; $i++) {
            self::assertError(400, 'invalid_token', $this->confirm($i % 2 === 0 ? 'tuttlingen' : null, str_repeat('A', 43), self::NEW_PASSWORD));
        }

        $response = $this->confirm('immendingen', $token, self::NEW_PASSWORD);

        self::assertError(429, 'too_many_attempts', $response);
        $retryAfter = (int) $response->headers()['Retry-After'];
        self::assertGreaterThan(800, $retryAfter);
        self::assertLessThanOrEqual(900, $retryAfter);
        self::assertSame(1, $this->countRows('fe_user_tokens'), 'gültiger Link bleibt erhalten');

        $this->pdo()->exec("UPDATE fe_auth_attempts SET attempted_at = attempted_at - INTERVAL 901 SECOND WHERE action = 'reset_confirm'");
        self::assertSame(200, $this->confirm('immendingen', $token, self::NEW_PASSWORD)->statusCode());
    }

    public function testSuccessfulResetLiftsLoginThrottleOfAccount(): void
    {
        $this->account('immendingen', 'user@example.org');
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ACCOUNT; $i++) {
            $this->login('immendingen', 'user@example.org', 'Falsches-Passwort-99');
        }
        self::assertError(429, 'too_many_attempts', $this->login('immendingen', 'user@example.org'));
        $token = $this->requestToken('immendingen', 'user@example.org');

        self::assertSame(200, $this->confirm('immendingen', $token, self::NEW_PASSWORD)->statusCode());

        self::assertSame(200, $this->login('immendingen', 'user@example.org', self::NEW_PASSWORD)->statusCode());
    }

    public function testMailFailureDoesNotChangeResponse(): void
    {
        $this->account('immendingen', 'user@example.org');
        $token = $this->requestToken('immendingen', 'user@example.org');
        $this->mailer->fail = true;

        self::assertSame(200, $this->confirm('immendingen', $token, self::NEW_PASSWORD)->statusCode());
        self::assertCount(1, $this->log);
        self::assertStringContainsString('mail_failed (password_changed, Konto #', $this->log[0]);
    }

    public function testRepositoryRedeemsLinkOnlyOnce(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $token = new UserTokenRepository($this->pdo())->replace($id, TokenPurpose::ResetPassword);
        $users = new UserRepository($this->pdo());
        $hash = self::passwordHash();

        self::assertTrue($users->resetPassword($id, $hash, $token->hash()));
        self::assertFalse($users->resetPassword($id, '$argon2id$v=19$m=1024,t=1,p=1$andererhash', $token->hash()));

        self::assertSame($hash, $this->hashOf($id));
    }

    public function testRepositoryRejectsLinkOfOtherAccount(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $other = $this->account('immendingen', 'andere@example.org');
        $token = new UserTokenRepository($this->pdo())->replace($other, TokenPurpose::ResetPassword);
        $hash = $this->hashOf($id);

        self::assertFalse(new UserRepository($this->pdo())->resetPassword($id, '$argon2id$v=19$m=1024,t=1,p=1$x', $token->hash()));

        self::assertSame($hash, $this->hashOf($id));
        self::assertSame(1, $this->countRows('fe_user_tokens'));
    }

    public function testRepositoryRefusesOpenTransaction(): void
    {
        $this->pdo()->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            new UserRepository($this->pdo())->resetPassword(1, self::passwordHash(), OneTimeToken::generate()->hash());
        } finally {
            $this->pdo()->rollBack();
        }
    }

    private function requestToken(?string $slug, string $email): string
    {
        $response = $this->send('POST', self::url($slug, 'auth/password-reset/request'), ['email' => $email]);
        self::assertSame(202, $response->statusCode(), $response->body());
        $text = end($this->mailer->sent)->text ?? self::fail('Keine Mail versendet.');
        self::assertSame(1, preg_match('/#token=([A-Za-z0-9_-]{43})$/m', $text, $m), $text);

        return $m[1];
    }

    private function confirm(?string $slug, string $token, string $password, ?string $cookie = null): JsonResponse
    {
        return $this->send('POST', self::url($slug, 'auth/password-reset/confirm'), ['token' => $token, 'new_password' => $password], $cookie);
    }

    private function hashOf(int $id): string
    {
        $stmt = $this->pdo()->prepare('SELECT password_hash FROM fe_users WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }
}
