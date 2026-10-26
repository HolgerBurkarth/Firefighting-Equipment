<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\Api\V1\Auth\LoginHandler;
use FirefightingEquipment\Api\V1\Auth\LogoutHandler;
use FirefightingEquipment\Auth\AccountUnavailableException;
use FirefightingEquipment\Auth\Authenticator;
use FirefightingEquipment\Auth\InvalidCredentialsException;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\Auth\SessionLifetime;
use FirefightingEquipment\Tests\Support\TestPasswordHasher;
use FirefightingEquipment\User\Password\PasswordHasher;
use FirefightingEquipment\User\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(LoginHandler::class)]
#[CoversClass(LogoutHandler::class)]
#[CoversClass(Authenticator::class)]
#[CoversClass(AuthJson::class)]
#[CoversClass(AccountUnavailableException::class)]
#[CoversClass(InvalidCredentialsException::class)]
final class LoginTest extends AuthTestCase
{
    public function testTenantUserLogsIn(): void
    {
        $id = $this->account('immendingen', 'user@example.org');

        $response = $this->login('immendingen', 'user@example.org');

        self::assertSame(200, $response->statusCode(), $response->body());
        $data = self::json($response);
        self::assertSame(
            [
                'id' => $id,
                'email' => 'user@example.org',
                'name' => 'Erika Musterfrau',
                'display_name' => 'Erika',
                'role' => 'user',
                'protected' => false,
                'notifications' => ['device_log' => false],
            ],
            $data['user'],
        );
        self::assertSame(['slug' => 'immendingen', 'name' => 'Feuerwehr immendingen'], $data['tenant']);
        self::assertSame($this->sessionIdOf($id), $data['session']['id']);
        self::assertSame('Chrome auf Android', $data['session']['device_label']);
        self::assertTrue($data['session']['current']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $data['session']['expires_at']);

        $cookie = $response->headers()['Set-Cookie'];
        self::assertMatchesRegularExpression(
            '/^ff_session=[A-Za-z0-9_-]{43}; Path=\/firefighting\/immendingen\/; Max-Age=7776000; HttpOnly; Secure; SameSite=Strict$/D',
            $cookie,
        );
        self::assertSame('no-store', $response->headers()['Cache-Control']);
    }

    public function testStoredSessionHasOnlyHashAndDeviceLabel(): void
    {
        $this->account('immendingen', 'user@example.org');

        $cookie = self::cookieValue($this->login('immendingen', 'user@example.org'));

        $row = $this->pdo()->query('SELECT token_hash, device_label FROM fe_sessions')->fetch();
        self::assertSame(hash('sha256', base64_decode(strtr((string) $cookie, '-_', '+/')), true), $row['token_hash']);
        self::assertSame('Chrome auf Android', $row['device_label']);
    }

    #[DataProvider('maxAgePerRole')]
    public function testCookieMaxAgeFollowsRole(?string $slug, string $role, int $maxAge): void
    {
        $this->account($slug, 'konto@example.org', $slug === null ? [] : ['role' => $role]);

        $response = $this->login($slug, 'konto@example.org');

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertStringContainsString('; Max-Age=' . $maxAge . ';', $response->headers()['Set-Cookie']);
        self::assertSame($role, self::json($response)['user']['role']);
    }

    /**
     * @return iterable<string, array{?string, string, int}>
     */
    public static function maxAgePerRole(): iterable
    {
        yield 'user' => ['immendingen', 'user', SessionLifetime::maxSeconds(UserRole::User)];
        yield 'admin' => ['immendingen', 'admin', SessionLifetime::maxSeconds(UserRole::Admin)];
        yield 'superadmin' => [null, 'superadmin', SessionLifetime::maxSeconds(UserRole::SuperAdmin)];
    }

    public function testSuperAdminLogsInOnlyUnderService(): void
    {
        $this->account(null, 'chef@example.org');

        $response = $this->login(null, 'chef@example.org');
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertNull(self::json($response)['tenant']);
        self::assertStringContainsString('; Path=/firefighting/service/;', $response->headers()['Set-Cookie']);

        self::assertError(401, 'invalid_credentials', $this->login('immendingen', 'chef@example.org'));
    }

    public function testTenantUserCannotLogInElsewhere(): void
    {
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);

        self::assertError(401, 'invalid_credentials', $this->login('tuttlingen', 'admin@example.org'));
        self::assertError(401, 'invalid_credentials', $this->login(null, 'admin@example.org'));
        self::assertSame(0, $this->countRows('fe_sessions'));
    }

    public function testSameEmailInTwoTenantsAreSeparateAccounts(): void
    {
        $a = $this->account('immendingen', 'doppelt@example.org');
        $b = $this->account('tuttlingen', 'doppelt@example.org');

        self::assertSame($a, self::json($this->login('immendingen', 'doppelt@example.org'))['user']['id']);
        self::assertSame($b, self::json($this->login('tuttlingen', 'doppelt@example.org'))['user']['id']);
    }

    public function testEmailIsNormalized(): void
    {
        $this->account('immendingen', 'user@example.org');

        self::assertSame(200, $this->login('immendingen', '  User@Example.ORG ')->statusCode());
    }

    public function testUnknownEmailAndWrongPasswordLookTheSame(): void
    {
        $this->account('immendingen', 'user@example.org');

        $wrongPassword = $this->login('immendingen', 'user@example.org', 'Falsches-Passwort-99');
        $unknownEmail = $this->login('immendingen', 'niemand@example.org');
        $invalidEmail = $this->login('immendingen', 'kein-at-zeichen');

        self::assertError(401, 'invalid_credentials', $wrongPassword);
        self::assertSame($wrongPassword->body(), $unknownEmail->body());
        self::assertSame($wrongPassword->body(), $invalidEmail->body());
        self::assertSame($wrongPassword->headers(), $unknownEmail->headers());
        self::assertArrayNotHasKey('Set-Cookie', $wrongPassword->headers());
        self::assertSame(0, $this->countRows('fe_sessions'));
    }

    public function testUnknownEmailTakesAboutAsLongAsWrongPassword(): void
    {
        // Mit den Standardparametern wie in Produktion (Hash des Kontos und Dummy-Hash).
        $hasher = new PasswordHasher();
        $app = $this->app(hasher: $hasher);
        $this->account('immendingen', 'user@example.org', ['password_hash' => $hasher->hash(self::PASSWORD)]);
        $measure = function (string $email) use ($app): float {
            $times = [];
            for ($i = 0; $i < 3; $i++) {
                $start = hrtime(true);
                $body = ['email' => $email, 'password' => 'Falsches-Passwort-99'];
                $this->send('POST', self::url('immendingen', 'auth/login'), $body, app: $app);
                $times[] = hrtime(true) - $start;
            }
            sort($times);

            return (float) $times[1];
        };

        $known = $measure('user@example.org');
        $unknown = $measure('niemand@example.org');

        // Argon2id dominiert die Laufzeit; ohne Dummy-Hash wäre die Antwort um ein Vielfaches schneller.
        self::assertGreaterThan(0.5, $unknown / $known);
        self::assertLessThan(2.0, $unknown / $known);
    }

    public function testInvitedAccountCannotLogIn(): void
    {
        $this->account('immendingen', 'neu@example.org', [
            'status' => 'invited', 'role' => 'admin', 'password_hash' => null,
            'email_verified_at' => null, 'name' => null, 'display_name' => null,
        ]);

        self::assertError(401, 'invalid_credentials', $this->login('immendingen', 'neu@example.org'));
    }

    public function testDeletedAccountCannotLogIn(): void
    {
        $id = $this->account('immendingen', 'weg@example.org');
        $this->pdo()->exec(
            "UPDATE fe_users SET status = 'deleted', email = NULL, email_verified_at = NULL, password_hash = NULL,"
            . ' name = NULL, display_name = NULL, deleted_at = UTC_TIMESTAMP() WHERE id = ' . $id,
        );

        self::assertError(401, 'invalid_credentials', $this->login('immendingen', 'weg@example.org'));
    }

    public function testUnverifiedAccountGetsHintOnlyWithCorrectPassword(): void
    {
        $this->registeredAccount('immendingen', 'neu@example.org', 'unverified');

        self::assertError(401, 'invalid_credentials', $this->login('immendingen', 'neu@example.org', 'Falsches-Passwort-99'));
        self::assertError(403, 'email_not_verified', $this->login('immendingen', 'neu@example.org'));
        self::assertSame(0, $this->countRows('fe_sessions'));
    }

    public function testPendingAccountGetsHintOnlyWithCorrectPassword(): void
    {
        $this->registeredAccount('immendingen', 'neu@example.org', 'pending');

        self::assertError(401, 'invalid_credentials', $this->login('immendingen', 'neu@example.org', 'Falsches-Passwort-99'));
        self::assertError(403, 'pending_approval', $this->login('immendingen', 'neu@example.org'));
        self::assertSame(0, $this->countRows('fe_sessions'));
    }

    public function testLockedAccountGetsHintOnlyWithCorrectPassword(): void
    {
        $this->account('immendingen', 'gesperrt@example.org', ['locked_at' => self::CREATED_AT]);
        $this->account(null, 'chef@example.org', ['locked_at' => self::CREATED_AT]);

        self::assertError(401, 'invalid_credentials', $this->login('immendingen', 'gesperrt@example.org', 'Falsches-Passwort-99'));
        self::assertError(403, 'account_locked', $this->login('immendingen', 'gesperrt@example.org'));
        self::assertError(403, 'account_locked', $this->login(null, 'chef@example.org'));
        self::assertSame(0, $this->countRows('fe_sessions'));
    }

    public function testLockTakesPrecedenceOverStatus(): void
    {
        $id = $this->registeredAccount('immendingen', 'neu@example.org', 'pending');
        $this->pdo()->exec('UPDATE fe_users SET locked_at = UTC_TIMESTAMP() WHERE id = ' . $id);

        self::assertError(403, 'account_locked', $this->login('immendingen', 'neu@example.org'));
    }

    public function testOutdatedHashIsReplacedWithoutEndingSessions(): void
    {
        // Andere Parameter als die der Anwendung im Test (TestPasswordHasher).
        $weak = password_hash(self::PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 1, 'threads' => 1]);
        $id = $this->account('immendingen', 'user@example.org', ['password_hash' => $weak]);
        $first = $this->loginCookie('immendingen', 'user@example.org');

        $hash = (string) $this->pdo()->query('SELECT password_hash FROM fe_users WHERE id = ' . $id)->fetchColumn();
        self::assertNotSame($weak, $hash);
        self::assertFalse(TestPasswordHasher::fast()->needsRehash($hash));
        self::assertTrue(password_verify(self::PASSWORD, $hash));
        self::assertSame(1, $this->sessionCountOf($id));
        self::assertSame(200, $this->send('POST', self::url('immendingen', 'auth/logout'), [], $first)->statusCode());

        // Mit dem neuen Hash geht es weiter.
        self::assertSame(200, $this->login('immendingen', 'user@example.org')->statusCode());
    }

    public function testCurrentHashIsNotRewritten(): void
    {
        $id = $this->account('immendingen', 'user@example.org');

        $this->login('immendingen', 'user@example.org');

        self::assertSame(self::passwordHash(), $this->pdo()->query('SELECT password_hash FROM fe_users WHERE id = ' . $id)->fetchColumn());
    }

    public function testLoginWithOldCookieReplacesOldSession(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $old = $this->loginCookie('immendingen', 'user@example.org');

        $response = $this->send('POST', self::url('immendingen', 'auth/login'), ['email' => 'user@example.org', 'password' => self::PASSWORD], $old);

        self::assertSame(200, $response->statusCode());
        self::assertNotSame($old, self::cookieValue($response));
        self::assertSame(1, $this->sessionCountOf($id));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'leer' => [[]];
        yield 'ohne Passwort' => [['email' => 'user@example.org']];
        yield 'ohne E-Mail' => [['password' => 'x']];
        yield 'Passwort als Zahl' => [['email' => 'user@example.org', 'password' => 1234567890]];
        yield 'E-Mail als Liste' => [['email' => ['user@example.org'], 'password' => 'x']];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIs400(array $body): void
    {
        self::assertError(400, 'invalid_request', $this->send('POST', self::url('immendingen', 'auth/login'), $body));
        self::assertSame(0, $this->countRows('fe_auth_attempts'));
    }

    public function testLoginRequiresJson(): void
    {
        $response = $this->send(
            'POST',
            self::url('immendingen', 'auth/login'),
            'email=user%40example.org&password=x',
            headers: ['content-type' => 'application/x-www-form-urlencoded'],
        );

        self::assertError(415, 'unsupported_media_type', $response);
    }

    public function testLoginCleansUpExpiredSessionsAndOldAttempts(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $this->loginCookie('immendingen', 'user@example.org');
        $this->ageSession($this->sessionIdOf($id), 31 * 86400);
        $this->insert('fe_auth_attempts', [
            'action' => 'register', 'tenant_id' => null, 'email_hash' => self::hash('alt'), 'ip_prefix' => "\x0a\0\0\0",
            'succeeded' => 0, 'attempted_at' => gmdate('Y-m-d H:i:s', time() - 25 * 3600),
        ]);

        $this->loginCookie('immendingen', 'user@example.org');

        self::assertSame(1, $this->sessionCountOf($id), 'abgelaufene Sitzung entfernt, neue angelegt');
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM fe_auth_attempts WHERE action = 'register'")->fetchColumn());
    }

    public function testAttemptsAreRecordedWithHashedEmailAndShortenedIp(): void
    {
        $tenantId = $this->tenant('immendingen')->id;
        $this->account('immendingen', 'user@example.org');

        $this->login('immendingen', 'User@Example.org', 'Falsches-Passwort-99');
        $this->login('immendingen', 'user@example.org');

        $rows = $this->pdo()->query('SELECT * FROM fe_auth_attempts ORDER BY id')->fetchAll();
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('login', $row['action']);
            self::assertSame($tenantId, (int) $row['tenant_id']);
            self::assertSame(LoginThrottle::emailHash('user@example.org'), $row['email_hash']);
            self::assertSame('c0000200', bin2hex($row['ip_prefix']));
        }
        self::assertSame([0, 1], array_map(static fn (array $r): int => (int) $r['succeeded'], $rows));
    }

    public function testLogoutEndsSessionAndClearsCookie(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');

        $response = $this->send('POST', self::url('immendingen', 'auth/logout'), [], $cookie);

        self::assertSame(200, $response->statusCode());
        self::assertSame('{"logged_out":true}', $response->body());
        self::assertSame(
            'ff_session=; Path=/firefighting/immendingen/; Max-Age=0; HttpOnly; Secure; SameSite=Strict',
            $response->headers()['Set-Cookie'],
        );
        self::assertSame(0, $this->sessionCountOf($id));
    }

    public function testLogoutIsRepeatableAndWorksWithoutCookie(): void
    {
        foreach ([null, 'kaputt', null] as $cookie) {
            $response = $this->send('POST', self::url(null, 'auth/logout'), [], $cookie);

            self::assertSame(200, $response->statusCode());
            self::assertStringContainsString('Path=/firefighting/service/; Max-Age=0', $response->headers()['Set-Cookie']);
        }
    }

    public function testLogoutRequiresJsonBody(): void
    {
        self::assertError(415, 'unsupported_media_type', $this->send('POST', self::url('immendingen', 'auth/logout')));
        self::assertError(
            403,
            'origin_not_allowed',
            $this->send('POST', self::url('immendingen', 'auth/logout'), [], headers: ['origin' => 'https://evil.example']),
        );
    }
}
