<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rechte aller Routen der Städteverwaltung über die echten Routen (Routes::service()): nur
 * Super-Admins. Ohne Sitzung 401; Admins und Benutzer einer Stadt haben unter service keine
 * passende Sitzung (401, auch wenn sie ihr Cookie mitschicken); unter einem Stadt-Pfad gibt es die
 * Routen nicht (404). Abgelehnte Anfragen ändern nichts.
 */
#[CoversClass(Routes::class)]
final class SuperAdminRoutesAccessTest extends AuthTestCase
{
    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function superAdminRoutes(): iterable
    {
        yield 'Städte auflisten' => ['GET', 'tenants', null];
        yield 'Kürzel prüfen' => ['GET', 'tenants/slug-available?slug=geisingen', null];
        yield 'Stadt anlegen' => ['POST', 'tenants', ['slug' => 'geisingen', 'name' => 'Feuerwehr Geisingen', 'admin_email' => 'admin@example.org']];
        yield 'Stadt anzeigen' => ['GET', 'tenants/{tenant}', null];
        yield 'umbenennen' => ['POST', 'tenants/{tenant}/rename', ['name' => 'Freiwillige Feuerwehr Immendingen']];
        yield 'sperren' => ['POST', 'tenants/{tenant}/lock', []];
        yield 'entsperren' => ['POST', 'tenants/{tenant}/unlock', []];
        yield 'Admin einladen' => ['POST', 'tenants/{tenant}/admins', ['email' => 'zweiter@example.org']];
        yield 'Benutzer ansehen' => ['GET', 'tenants/{tenant}/users', null];
        yield 'Einladung erneut senden' => ['POST', 'tenants/{tenant}/users/{user}/resend-invitation', []];
        yield 'Super-Admins auflisten' => ['GET', 'superadmins', null];
        yield 'Super-Admin sperren' => ['POST', 'superadmins/{superadmin}/lock', []];
        yield 'Super-Admin entsperren' => ['POST', 'superadmins/{superadmin}/unlock', []];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('superAdminRoutes')]
    public function testOnlySuperAdmin(string $method, string $path, ?array $body): void
    {
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->account('immendingen', 'user@example.org');
        $this->account(null, 'chef@example.org');
        $other = $this->account(null, 'zweiter@example.org');
        $tenant = $this->tenant('immendingen');
        $invited = new UserRepository($this->pdo())->inviteAdmin($tenant, EmailAddress::fromString('neu@example.org'))->user->id;
        $path = str_replace(['{tenant}', '{user}', '{superadmin}'], [(string) $tenant->id, (string) $invited, (string) $other], $path);
        $url = self::url(null, $path);
        $admin = $this->loginCookie('immendingen', 'admin@example.org');
        $user = $this->loginCookie('immendingen', 'user@example.org');
        $before = $this->snapshot();

        self::assertError(401, 'not_authenticated', $this->send($method, $url, $body));
        self::assertError(401, 'not_authenticated', $this->send($method, $url, $body, $admin));
        self::assertError(401, 'not_authenticated', $this->send($method, $url, $body, $user));
        self::assertError(404, 'not_found', $this->send($method, self::url('immendingen', $path), $body, $admin));
        self::assertSame($before, $this->snapshot(), 'nichts geändert');
        self::assertSame([], $this->mailer->sent);

        $response = $this->send($method, $url, $body, $this->loginCookie(null, 'chef@example.org'));
        self::assertContains($response->statusCode(), [200, 201], $response->body());
    }

    /**
     * Stand der Fachtabellen (ohne Sitzungen und Anmeldeversuche, die die Logins erzeugen).
     */
    private function snapshot(): string
    {
        return json_encode([
            $this->pdo()->query('SELECT * FROM fe_tenants ORDER BY id')->fetchAll(),
            $this->pdo()->query('SELECT * FROM fe_users ORDER BY id')->fetchAll(),
            array_map(
                static fn (array $row): array => [...$row, 'token_hash' => bin2hex($row['token_hash'])],
                $this->pdo()->query('SELECT user_id, purpose, token_hash FROM fe_user_tokens ORDER BY id')->fetchAll(),
            ),
        ], JSON_THROW_ON_ERROR);
    }
}
