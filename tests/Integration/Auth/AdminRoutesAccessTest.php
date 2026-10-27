<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Device\Import\DeviceImport;
use FirefightingEquipment\Device\Import\DeviceImportFile;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Tenant\Logo\LogoRenderer;
use FirefightingEquipment\Tenant\TenantAppearanceRepository;
use FirefightingEquipment\Tests\Support\TestPng;
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
    /** CSV-Import: ändert den Status von MA-01 */
    private const string IMPORT_FILE = "Kennung;Status
MA-01;defekt
";

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
        yield 'Import prüfen' => ['POST', 'devices/import/preview', ['file' => '{import_file}']];
        yield 'Import übernehmen' => ['POST', 'devices/import', ['file' => '{import_file}', 'token' => '{import_token}']];
        yield 'Akzentfarbe setzen' => ['POST', 'tenant/appearance', ['accent_color' => '#1a237e']];
        yield 'Logo hochladen' => ['POST', 'tenant/logo', ['file' => '{logo_file}']];
        yield 'Logo entfernen' => ['DELETE', 'tenant/logo', []];
        yield 'Hinweise auflisten' => ['GET', 'notices?status=all', null];
        yield 'Hinweis anzeigen' => ['GET', 'notices/{notice}', null];
        yield 'Hinweis erledigen' => ['POST', 'notices/{notice}/resolve', ['note' => 'Geklärt']];
        yield 'Hinweis wieder öffnen' => ['POST', 'notices/{notice}/reopen', []];
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
        $notice = $this->createNotice($device, $vehicle, $this->userIdOf('immendingen', 'user@example.org'), $method === 'POST' && str_ends_with($path, '/reopen'));
        $import = self::IMPORT_FILE;
        $importToken = new DeviceImport($this->pdo())->preview($this->tenant('immendingen'), DeviceImportFile::parse($import))->token;
        $placeholders = ['{code}' => $code, '{user}' => $user, '{type}' => $type, '{location}' => $location, '{device}' => $device, '{retired}' => $retired, '{notice}' => $notice];
        $path = strtr($path, array_map('strval', $placeholders));
        $placeholders += ['{import_file}' => base64_encode($import), '{import_token}' => $importToken, '{logo_file}' => base64_encode(TestPng::create())];
        if ($method === 'DELETE' && $path === 'tenant/logo') {
            new TenantAppearanceRepository($this->pdo())->saveLogo($this->tenant('immendingen'), LogoRenderer::fromUpload(TestPng::create()), $admin);
        }
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
            $this->pdo()->query('SELECT * FROM fe_tenants ORDER BY id')->fetchAll(),
            $this->pdo()->query('SELECT tenant_id, version, updated_by FROM fe_tenant_logos ORDER BY tenant_id')->fetchAll(),
            $this->pdo()->query('SELECT * FROM fe_device_notices ORDER BY id')->fetchAll(),
            $this->pdo()->query('SELECT * FROM fe_device_notice_actions ORDER BY id')->fetchAll(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Hinweis (offen bzw. erledigt) zu einer Buchung des Benutzers, per SQL.
     */
    private function createNotice(int $device, int $location, int $userId, bool $resolved): int
    {
        $tenantId = $this->tenant('immendingen')->id;
        $booking = $this->insert('fe_device_bookings', [
            'tenant_id' => $tenantId, 'client_ref' => '3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f', 'user_id' => $userId,
            'device_id' => $device, 'request_hash' => str_repeat(" ", 32), 'outcome' => 'unchanged',
        ]);

        return $this->insert('fe_device_notices', [
            'tenant_id' => $tenantId, 'device_id' => $device, 'booking_id' => $booking, 'kind' => 'stale_confirmed',
            'stale_confirmed' => 1, 'user_id' => $userId, 'occurred_at' => '2026-01-01 08:00:00', 'requested_status' => 'ready',
            'other_occurred_at' => '2025-12-01 08:00:00', 'other_location_id' => $location, 'other_status' => 'ready',
            'status' => $resolved ? 'resolved' : 'open',
        ]);
    }

    private function userIdOf(string $slug, string $email): int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM fe_users WHERE tenant_id = ? AND email = ?');
        $stmt->execute([$this->tenant($slug)->id, $email]);

        return (int) $stmt->fetchColumn();
    }
}
