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
        yield 'Gerätetyp anlegen' => ['POST', 'device-types', ['name' => 'Funkgerät']];
        yield 'Gerätetyp umbenennen' => ['POST', 'device-types/{type}/rename', ['name' => 'Handfunkgerät']];
        yield 'Gerätetyp deaktivieren' => ['POST', 'device-types/{type}/deactivate', []];
        yield 'Gerätetyp aktivieren' => ['POST', 'device-types/{type}/activate', []];
        yield 'Standort anlegen' => ['POST', 'locations', ['name' => 'HLF 20', 'kind' => 'vehicle']];
        yield 'Standort umbenennen' => ['POST', 'locations/{location}/rename', ['name' => 'LF 10']];
        yield 'Standort sperren' => ['POST', 'locations/{location}/lock', []];
        yield 'Standort entsperren' => ['POST', 'locations/{location}/unlock', []];
        yield 'Gerät anlegen' => ['POST', 'devices', ['identifier' => 'MA-02', 'device_type_id' => '{type}', 'location_id' => '{location}']];
        yield 'Stammdaten ändern' => ['POST', 'devices/{device}/fields', ['version' => 1, 'model' => 'FPS 7000']];
        yield 'Prüfdatum setzen' => ['POST', 'devices/{device}/inspection-due', ['version' => 1, 'next_inspection_due' => '2027-03-01']];
        yield 'Gerät aussondern' => ['POST', 'devices/{device}/retire', ['version' => 1]];
        yield 'Gerät wieder aufnehmen' => ['POST', 'devices/{retired}/reinstate', ['version' => 1, 'status' => 'ready']];
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
        $type = $this->insert('fe_device_types', ['tenant_id' => $this->tenant('immendingen')->id, 'name' => 'Atemschutzmaske']);
        $location = $this->insert('fe_locations', ['tenant_id' => $this->tenant('immendingen')->id, 'kind' => 'room', 'name' => 'Lager', 'level' => 1]);
        $vehicle = $this->insert('fe_locations', ['tenant_id' => $this->tenant('immendingen')->id, 'kind' => 'vehicle', 'name' => 'LF 8', 'level' => 1]);
        $device = $this->insert('fe_devices', [
            'tenant_id' => $this->tenant('immendingen')->id, 'identifier' => 'MA-01', 'device_type_id' => $type, 'location_id' => $vehicle, 'status' => 'ready',
        ]);
        $retired = $this->insert('fe_devices', [
            'tenant_id' => $this->tenant('immendingen')->id, 'identifier' => 'MA-09', 'device_type_id' => $type, 'location_id' => $vehicle, 'status' => 'retired',
        ]);
        $placeholders = ['{code}' => $code, '{user}' => $user, '{type}' => $type, '{location}' => $location, '{device}' => $device, '{retired}' => $retired];
        $path = strtr($path, array_map('strval', $placeholders));
        // Platzhalter als ganzer Wert im Body werden zur ID (echte JSON-Ganzzahl).
        $body = $body === null ? null : array_map(static fn (mixed $v): mixed => is_string($v) ? ($placeholders[$v] ?? $v) : $v, $body);
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
            $this->pdo()->query('SELECT * FROM fe_device_types ORDER BY id')->fetchAll(),
            $this->pdo()->query('SELECT * FROM fe_locations ORDER BY id')->fetchAll(),
            $this->pdo()->query('SELECT * FROM fe_devices ORDER BY id')->fetchAll(),
            $this->pdo()->query('SELECT * FROM fe_device_events ORDER BY id')->fetchAll(),
        ], JSON_THROW_ON_ERROR);
    }
}
