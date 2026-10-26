<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Http\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rechte der lesenden Routen unter einer Stadt (Access::Authenticated) über die echten Routen:
 * Benutzer und Admins der eigenen Stadt 200; ohne Anmeldung, Admin einer anderen Stadt und
 * Super-Admin unter einem Stadt-Pfad 401; unter service gibt es die Routen nicht (404).
 */
#[CoversClass(Routes::class)]
final class TenantReadRoutesAccessTest extends AuthTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function readRoutes(): iterable
    {
        yield 'Gerätetypen' => ['device-types'];
        yield 'Gerätetypen mit inaktiven' => ['device-types?include_inactive=1'];
        yield 'Standorte' => ['locations'];
        yield 'Standorte mit gesperrten' => ['locations?include_inactive=1'];
        yield 'Standort anzeigen' => ['locations/{location}'];
        yield 'Geräte' => ['devices'];
        yield 'Geräte gefiltert' => ['devices?location_id={location}&include_sublocations=1&status=ready,retired&q=ma&sort=next_inspection_due&limit=10&offset=0'];
        yield 'Gerät anzeigen' => ['devices/{device}'];
        yield 'Historie' => ['devices/{device}/events?limit=10&offset=0'];
        yield 'Prüffristen' => ['inspection-due'];
        yield 'Prüffristen gefiltert' => ['inspection-due?days=60&include_without_date=1&location_id={location}&include_sublocations=1&limit=10'];
        yield 'Zusammenfassung' => ['device-summary'];
    }

    #[DataProvider('readRoutes')]
    public function testOnlyAccountsOfOwnTenant(string $path): void
    {
        $tenantId = $this->tenant('immendingen')->id;
        $this->account('immendingen', 'user@example.org');
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);
        $this->account(null, 'chef@example.org');
        $location = $this->insert('fe_locations', ['tenant_id' => $tenantId, 'kind' => 'room', 'name' => 'Lager', 'level' => 1]);
        $type = $this->insert('fe_device_types', ['tenant_id' => $tenantId, 'name' => 'Atemschutzmaske']);
        $device = $this->insert('fe_devices', ['tenant_id' => $tenantId, 'identifier' => 'MA-01', 'device_type_id' => $type, 'location_id' => $location, 'status' => 'ready']);
        $path = str_replace(['{location}', '{device}'], [(string) $location, (string) $device], $path);
        $url = self::url('immendingen', $path);

        self::assertError(401, 'not_authenticated', $this->send('GET', $url));
        self::assertError(401, 'not_authenticated', $this->send('GET', $url, cookie: $this->loginCookie('tuttlingen', 'admin@example.org')));
        $superAdmin = $this->loginCookie(null, 'chef@example.org');
        self::assertError(401, 'not_authenticated', $this->send('GET', $url, cookie: $superAdmin));
        self::assertError(404, 'not_found', $this->send('GET', self::url(null, $path), cookie: $superAdmin));

        foreach (['user@example.org', 'admin@example.org'] as $email) {
            $response = $this->send('GET', $url, cookie: $this->loginCookie('immendingen', $email));
            self::assertSame(200, $response->statusCode(), $response->body());
        }
    }
}
