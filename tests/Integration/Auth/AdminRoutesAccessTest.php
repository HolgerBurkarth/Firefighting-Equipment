<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Http\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rechte aller Admin-Routen über die echten Routen (Routes::tenant()): nur Admins der eigenen
 * Stadt; Benutzer 403, Admin einer anderen Stadt und Super-Admin unter einem Stadt-Pfad 401 (die
 * Sitzung passt nicht zum Bereich), unter service gibt es die Routen nicht (404). Abgelehnte
 * Anfragen ändern nichts.
 */
#[CoversClass(Routes::class)]
final class AdminRoutesAccessTest extends AuthTestCase
{
    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function adminRoutes(): iterable
    {
        yield 'Codes auflisten' => ['GET', 'invitation-codes', null];
        yield 'Code anlegen' => ['POST', 'invitation-codes', ['valid_days' => 1, 'max_uses' => 1]];
        yield 'Code widerrufen' => ['POST', 'invitation-codes/{code}/revoke', []];
        yield 'Benutzer auflisten' => ['GET', 'users', null];
        yield 'Benutzer anzeigen' => ['GET', 'users/{user}', null];
        yield 'freischalten' => ['POST', 'users/{user}/approve', ['display_name' => 'Max', 'role' => 'user']];
        yield 'ablehnen' => ['POST', 'users/{user}/reject', []];
        yield 'sperren' => ['POST', 'users/{user}/lock', []];
        yield 'entsperren' => ['POST', 'users/{user}/unlock', []];
        yield 'Rolle ändern' => ['POST', 'users/{user}/role', ['role' => 'admin']];
        yield 'Anzeigename ändern' => ['POST', 'users/{user}/display-name', ['display_name' => 'Max']];
        yield 'löschen' => ['DELETE', 'users/{user}', []];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('adminRoutes')]
    public function testOnlyAdminOfOwnTenant(string $method, string $path, ?array $body): void
    {
        $admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->account('immendingen', 'user@example.org');
        $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);
        $this->account(null, 'chef@example.org');
        $code = $this->createInvitationCode($this->tenant('immendingen')->id, $admin);
        $user = $this->registeredAccount('immendingen', 'max@example.org', 'pending');
        $path = str_replace(['{code}', '{user}'], [(string) $code, (string) $user], $path);
        $url = self::url('immendingen', $path);
        $before = $this->snapshot();

        self::assertError(401, 'not_authenticated', $this->send($method, $url, $body));
        self::assertError(403, 'forbidden', $this->send($method, $url, $body, $this->loginCookie('immendingen', 'user@example.org')));
        self::assertError(401, 'not_authenticated', $this->send($method, $url, $body, $this->loginCookie('tuttlingen', 'admin@example.org')));
        $superAdmin = $this->loginCookie(null, 'chef@example.org');
        self::assertError(401, 'not_authenticated', $this->send($method, $url, $body, $superAdmin));
        self::assertError(404, 'not_found', $this->send($method, self::url(null, $path), $body, $superAdmin));
        self::assertSame($before, $this->snapshot(), 'nichts geändert');

        $response = $this->send($method, $url, $body, $this->loginCookie('immendingen', 'admin@example.org'));
        self::assertContains($response->statusCode(), [200, 201], $response->body());
    }

    /**
     * Stand der Fachtabellen (ohne Sitzungen und Anmeldeversuche, die die Logins erzeugen).
     */
    private function snapshot(): string
    {
        return json_encode([
            $this->pdo()->query('SELECT * FROM fe_users ORDER BY id')->fetchAll(),
            array_map(
                static fn (array $row): array => [...$row, 'code_hash' => bin2hex($row['code_hash'])],
                $this->pdo()->query('SELECT * FROM fe_invitation_codes ORDER BY id')->fetchAll(),
            ),
            $this->pdo()->query('SELECT user_id, purpose FROM fe_user_tokens ORDER BY id')->fetchAll(),
        ], JSON_THROW_ON_ERROR);
    }
}
