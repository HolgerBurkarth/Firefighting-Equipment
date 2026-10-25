<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Auth\PasswordChangeHandler;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\Http\JsonResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(PasswordChangeHandler::class)]
final class PasswordChangeTest extends AuthTestCase
{
    private const string NEW_PASSWORD = 'Schlauch-Leiter-Wasser-7';

    public function testChangeEndsOtherSessionsAndReplacesCurrent(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $other = $this->loginCookie('immendingen', 'user@example.org');
        $firefox = $this->send(
            'POST',
            self::url('immendingen', 'auth/login'),
            ['email' => 'user@example.org', 'password' => self::PASSWORD],
            headers: ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0'],
        );
        $current = (string) self::cookieValue($firefox);
        $hashBefore = $this->hashOf($id);

        $response = $this->change($current, self::PASSWORD, self::NEW_PASSWORD);

        self::assertSame(200, $response->statusCode(), $response->body());
        $new = (string) self::cookieValue($response);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $new);
        self::assertNotSame($current, $new);
        self::assertStringContainsString('Path=/firefighting/immendingen/; Max-Age=7776000; HttpOnly; Secure; SameSite=Strict', $response->headers()['Set-Cookie']);
        self::assertSame('Firefox auf Windows', self::json($response)['session']['device_label'], 'Gerät der Sitzung bleibt');
        self::assertSame($this->sessionIdOf($id), self::json($response)['session']['id']);

        self::assertSame(1, $this->sessionCountOf($id));
        self::assertSame(200, $this->me($new)->statusCode());
        self::assertError(401, 'not_authenticated', $this->me($current));
        self::assertError(401, 'not_authenticated', $this->me($other));

        self::assertNotSame($hashBefore, $this->hashOf($id));
        self::assertError(401, 'invalid_credentials', $this->login('immendingen', 'user@example.org'));
        self::assertSame(200, $this->login('immendingen', 'user@example.org', self::NEW_PASSWORD)->statusCode());
    }

    public function testSuperAdminChangesPasswordUnderService(): void
    {
        $id = $this->account(null, 'chef@example.org');
        $cookie = $this->loginCookie(null, 'chef@example.org');

        $response = $this->send(
            'POST',
            self::url(null, 'auth/password'),
            ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD],
            $cookie,
        );

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertStringContainsString('Path=/firefighting/service/; Max-Age=43200;', $response->headers()['Set-Cookie']);
        self::assertSame(1, $this->sessionCountOf($id));
    }

    public function testWrongCurrentPasswordChangesNothing(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');
        $hash = $this->hashOf($id);

        $response = $this->change($cookie, 'Falsches-Passwort-99', self::NEW_PASSWORD);

        self::assertError(403, 'invalid_current_password', $response);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
        self::assertSame($hash, $this->hashOf($id));
        self::assertSame(200, $this->me($cookie)->statusCode(), 'Sitzung bleibt');
    }

    public function testNotificationIsSentAfterChange(): void
    {
        $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');

        self::assertSame(200, $this->change($cookie, self::PASSWORD, self::NEW_PASSWORD)->statusCode());

        $mail = $this->mailer->only();
        self::assertSame('user@example.org', $mail->to->value);
        self::assertSame('Erika', $mail->toName);
        self::assertSame('Ihr Passwort wurde geändert', $mail->subject);
        self::assertStringContainsString("\nGerät: Chrome auf Android\n", $mail->text);
        self::assertStringContainsString('(Feuerwehr immendingen)', $mail->text);
        self::assertStringContainsString("\n" . self::BASE_URL . "/firefighting/immendingen/\n", $mail->text);
        self::assertSame([], $this->log);
    }

    public function testMailFailureDoesNotChangeResponse(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');
        $this->mailer->fail = true;

        $response = $this->change($cookie, self::PASSWORD, self::NEW_PASSWORD);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertNotNull(self::cookieValue($response));
        self::assertSame(1, $this->sessionCountOf($id));
        self::assertCount(1, $this->log);
        self::assertStringContainsString(sprintf('mail_failed (password_changed, Konto #%d)', $id), $this->log[0]);
    }

    public function testRejectedChangeSendsNoNotification(): void
    {
        $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');

        $this->change($cookie, 'Falsches-Passwort-99', self::NEW_PASSWORD);
        $this->change($cookie, self::PASSWORD, 'kurz');

        self::assertSame([], $this->mailer->sent);
    }

    public function testWrongCurrentPasswordCountsIntoThrottle(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ACCOUNT; $i++) {
            self::assertError(403, 'invalid_current_password', $this->change($cookie, 'Falsches-Passwort-99', self::NEW_PASSWORD));
        }

        $response = $this->change($cookie, self::PASSWORD, self::NEW_PASSWORD);
        self::assertError(429, 'too_many_attempts', $response);
        self::assertArrayHasKey('Retry-After', $response->headers());
        self::assertError(429, 'too_many_attempts', $this->login('immendingen', 'user@example.org'));
        self::assertSame(1, $this->sessionCountOf($id));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function weakPasswords(): iterable
    {
        yield 'zu kurz' => ['kurz-1', 'mindestens 10 Zeichen'];
        yield 'Sperrliste' => ['Feuerwehr2025!', 'zu verbreitet'];
        yield 'Name' => ['Musterfrau-Pumpe-12', 'Teile der E-Mail-Adresse, des Namens oder des Stadtnamens'];
        yield 'E-Mail' => ['benutzer-Pumpe-12345', 'Teile der E-Mail-Adresse'];
        yield 'Stadt' => ['Immendingen-Pumpe-1', 'Stadtnamens'];
        yield 'Steuerzeichen' => ["Pumpe-Leiter\n-Wasser", 'Steuerzeichen'];
    }

    #[DataProvider('weakPasswords')]
    public function testPasswordRulesApplyWithPersonalContext(string $newPassword, string $message): void
    {
        $id = $this->account('immendingen', 'benutzer@example.org');
        $cookie = $this->loginCookie('immendingen', 'benutzer@example.org');
        $hash = $this->hashOf($id);

        $response = $this->change($cookie, self::PASSWORD, $newPassword);

        self::assertError(422, 'weak_password', $response);
        self::assertStringContainsString($message, self::json($response)['message']);
        self::assertSame($hash, $this->hashOf($id));
        self::assertSame(200, $this->me($cookie)->statusCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'leer' => [[]];
        yield 'ohne neues Passwort' => [['current_password' => self::PASSWORD]];
        yield 'neues Passwort als Zahl' => [['current_password' => self::PASSWORD, 'new_password' => 1234567890123]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIs400(array $body): void
    {
        $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');

        self::assertError(400, 'invalid_request', $this->send('POST', self::url('immendingen', 'auth/password'), $body, $cookie));
    }

    public function testRequiresSessionAndAllowedOrigin(): void
    {
        $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');
        $body = ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD];

        self::assertError(401, 'not_authenticated', $this->send('POST', self::url('immendingen', 'auth/password'), $body));
        self::assertError(
            403,
            'origin_not_allowed',
            $this->send('POST', self::url('immendingen', 'auth/password'), $body, $cookie, ['origin' => 'https://evil.example']),
        );
        self::assertError(
            415,
            'unsupported_media_type',
            $this->send('POST', self::url('immendingen', 'auth/password'), (string) json_encode($body), $cookie, ['content-type' => 'text/plain']),
        );
        self::assertSame(
            200,
            $this->send('POST', self::url('immendingen', 'auth/password'), $body, $cookie, ['origin' => 'https://www.prodad.de'])->statusCode(),
        );
    }

    private function change(string $cookie, string $current, string $new): JsonResponse
    {
        return $this->send(
            'POST',
            self::url('immendingen', 'auth/password'),
            ['current_password' => $current, 'new_password' => $new],
            $cookie,
        );
    }

    private function me(string $cookie): JsonResponse
    {
        return $this->send('GET', self::url('immendingen', 'auth/me'), cookie: $cookie);
    }

    private function hashOf(int $id): string
    {
        return (string) $this->pdo()->query('SELECT password_hash FROM fe_users WHERE id = ' . $id)->fetchColumn();
    }
}
