<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Role\RoleDraft;
use FirefightingEquipment\Role\RoleRepository;
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
     * Methode, Pfad, Body und optional die Antworten, mit denen der Super-Admin durchkommt (Standard
     * 200/201).
     *
     * @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>|null, 3?: list<int>}>
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
        // Kommt durch, lehnt in Immendingen aber fachlich ab (Claude-Zugang nur in der Test-Stadt,
        // ClaudeAvailability); die Wirkung prüft ClaudeAccessTest.
        yield 'Claude-Zugang setzen' => ['POST', 'tenants/{tenant}/users/{user}/claude-access', ['access' => 'read'], [409]];
        yield 'Claude-Verbindungen trennen' => ['POST', 'tenants/{tenant}/users/{user}/claude-connections/end', [], [409]];
        yield 'Rechte-Katalog' => ['GET', 'permission-catalog', null];
        yield 'Aufgaben der Standorte lesen' => ['GET', 'tenants/{tenant}/location-tasks', null];
        yield 'Aufgaben der Standorte speichern' => ['POST', 'tenants/{tenant}/location-tasks', ['tasks' => [['task' => 'store', 'enabled' => true]]]];
        yield 'Rollen auflisten' => ['GET', 'tenants/{tenant}/roles', null];
        yield 'Rolle anlegen' => ['POST', 'tenants/{tenant}/roles', ['name' => 'Live', 'areas' => ['search' => 'read'], 'actions' => [], 'locations' => null, 'device_types' => null]];
        yield 'Rolle kopieren' => ['POST', 'tenants/{tenant}/roles', ['copy_of' => '{role}']];
        yield 'Rolle übernehmen' => ['POST', 'tenants/{tenant}/roles/import', ['source_role_id' => '{other-role}']];
        yield 'Rolle anzeigen' => ['GET', 'tenants/{tenant}/roles/{role}', null];
        yield 'Rolle speichern' => ['POST', 'tenants/{tenant}/roles/{role}', ['version' => 1, 'name' => 'Neu', 'areas' => [], 'actions' => [], 'locations' => null, 'device_types' => null]];
        yield 'Rolle löschen' => ['DELETE', 'tenants/{tenant}/roles/{role}', []];
        yield 'Auswahl für den Datenfilter' => ['GET', 'tenants/{tenant}/role-filter-options', null];
        yield 'Super-Admins auflisten' => ['GET', 'superadmins', null];
        yield 'Super-Admin sperren' => ['POST', 'superadmins/{superadmin}/lock', []];
        yield 'Super-Admin entsperren' => ['POST', 'superadmins/{superadmin}/unlock', []];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<int>                 $passes
     */
    #[DataProvider('superAdminRoutes')]
    public function testOnlySuperAdmin(string $method, string $path, ?array $body, array $passes = [200, 201]): void
    {
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->account('immendingen', 'user@example.org');
        $chef = $this->account(null, 'chef@example.org');
        $other = $this->account(null, 'zweiter@example.org');
        $tenant = $this->tenant('immendingen');
        $invited = new UserRepository($this->pdo())->inviteAdmin($tenant, EmailAddress::fromString('neu@example.org'))->user->id;
        $roles = new RoleRepository($this->pdo());
        $role = $roles->create($tenant, RoleDraft::of('Gerätewart', null, [], [], null, null), $chef)->id;
        $otherRole = $roles->create($this->tenant('tuttlingen'), RoleDraft::of('Träger', null, [], [], null, null), $chef)->id;
        $placeholders = ['{tenant}' => $tenant->id, '{user}' => $invited, '{superadmin}' => $other, '{role}' => $role, '{other-role}' => $otherRole];
        $path = strtr($path, array_map('strval', $placeholders));
        if ($body !== null) {
            $body = array_map(static fn (mixed $value): mixed => is_string($value) ? ($placeholders[$value] ?? $value) : $value, $body);
        }
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
        self::assertContains($response->statusCode(), $passes, $response->body());
    }

    /**
     * Stand der Fachtabellen (ohne Sitzungen und Anmeldeversuche, die die Logins erzeugen).
     */
    private function snapshot(): string
    {
        return json_encode([
            $this->pdo()->query('SELECT * FROM fe_tenants ORDER BY id')->fetchAll(),
            $this->pdo()->query('SELECT * FROM fe_users ORDER BY id')->fetchAll(),
            $this->pdo()->query('SELECT * FROM fe_roles ORDER BY id')->fetchAll(),
            $this->pdo()->query('SELECT * FROM fe_role_areas ORDER BY role_id, area')->fetchAll(),
            array_map(
                static fn (array $row): array => [...$row, 'token_hash' => bin2hex($row['token_hash'])],
                $this->pdo()->query('SELECT user_id, purpose, token_hash FROM fe_user_tokens ORDER BY id')->fetchAll(),
            ),
        ], JSON_THROW_ON_ERROR);
    }
}
