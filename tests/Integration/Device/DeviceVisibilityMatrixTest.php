<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Csv\CsvFormat;
use FirefightingEquipment\Permission\DeviceVisibility;
use FirefightingEquipment\Permission\PermissionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Datenfilter „Sieht nur …“ (Plan Phase 1, Abschnitt 4) über die ganze Anwendung: zwei Gerätetypen
 * (Atemschutzmaske, Funkgerät) × drei Standorte (HLF 20 / G1, Lager, RTW), je ein Gerät; jede Abfrage
 * einmal mit der Rolle Admin und mit drei Rollen: nur Standort HLF 20 (mit Fächern), nur
 * Atemschutzmaske, beides (UND). Dazu: Schreiben auf Geräte und Ziele außerhalb (404/400),
 * „anderer Standort“ in Verlauf, Hinweisen und Export, Versionskonflikt und Wiederholung nach dem
 * Herausbuchen (404), leerer Filter (nichts), nur ein Fach, Rechte nach F3.
 *
 * Geräte: M-G1 (Maske, angelegt im Lager und nach G1 gebucht), F-G1, M-L, F-L, M-R, F-R.
 */
#[CoversClass(DeviceVisibility::class)]
#[CoversClass(PermissionRepository::class)]
final class DeviceVisibilityMatrixTest extends DeviceApiTestCase
{
    use FilteredRoles;
    use OfflineBookings;

    private const array ALL = ['F-G1', 'F-L', 'F-R', 'M-G1', 'M-L', 'M-R'];

    /** Rolle => sichtbare Geräte */
    private const array VISIBLE = [
        'admin' => self::ALL,
        'standort' => ['F-G1', 'M-G1'],
        'typ' => ['M-G1', 'M-L', 'M-R'],
        'beide' => ['M-G1'],
    ];

    private int $radioId;
    private int $g2;
    private int $rtw;
    /** @var array<string, int> Kennung => ID */
    private array $devices = [];
    /** @var array<string, int> Kennung => Hinweis */
    private array $notices = [];
    private int $accountCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOffline();

        $this->radioId = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $this->g2 = $this->createSubLocation($this->tenantId, $this->hlf, ['name' => 'G2']);
        $this->rtw = $this->createLocation($this->tenantId, ['name' => 'RTW']);

        foreach ([['M-G1', $this->typeId, $this->room], ['F-G1', $this->radioId, $this->g1], ['M-L', $this->typeId, $this->room],
            ['F-L', $this->radioId, $this->room], ['M-R', $this->typeId, $this->rtw], ['F-R', $this->radioId, $this->rtw]] as [$identifier, $type, $location]) {
            $this->devices[$identifier] = $this->createViaApi($identifier, [
                'device_type_id' => $type,
                'location_id' => $location,
                'scan_code' => 'SC-' . $identifier,
                'next_inspection_due' => $this->now->modify('-1 day')->format('Y-m-d'),
            ])['id'];
        }
        // M-G1 kam aus dem Lager: Verlauf mit einem Standort außerhalb des Standortfilters.
        $this->assertStatus(200, $this->api('POST', 'devices/' . $this->devices['M-G1'] . '/state', ['version' => 1, 'location_id' => $this->g1]));

        // Hinweise (nur Historie: ältere Buchung mit veralteter Version) zu M-G1 (gewünscht: Lager)
        // und M-L (gewünscht: G1).
        $this->assertStatus(200, $this->api('POST', 'devices/' . $this->devices['M-L'] . '/state', ['version' => 1, 'status' => 'maintenance']));
        foreach (['M-G1' => $this->room, 'M-L' => $this->g1] as $identifier => $wanted) {
            $id = $this->devices[$identifier];
            $this->setStateChangedAt($id, '-1 hour');
            $json = $this->offline($id, ['version' => 1, 'location_id' => $wanted, 'occurred_at' => $this->at('-1 day')]);
            self::assertSame('history_only', $json['result']['outcome']);
            $this->notices[$identifier] = $json['result']['notice_id'];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roles(): iterable
    {
        foreach (array_keys(self::VISIBLE) as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('roles')]
    public function testReadsShowOnlyVisibleDevices(string $role): void
    {
        $cookie = $this->cookie($role);
        $visible = self::VISIBLE[$role];

        $list = $this->get('devices?limit=200', $cookie);
        self::assertSame($visible, self::identifiers($list['devices']));
        self::assertSame(count($visible), $list['total']);
        self::assertSame(array_values(array_filter($visible, static fn (string $i): bool => str_starts_with($i, 'F-'))), self::identifiers($this->get('devices?q=F-', $cookie)['devices']));

        foreach (self::ALL as $identifier) {
            $id = $this->devices[$identifier];
            $sees = in_array($identifier, $visible, true);
            self::assertSame($sees ? [$identifier] : [], self::identifiers($this->get('devices?scan_code=SC-' . $identifier, $cookie)['devices']), $identifier);
            $this->assertStatus($sees ? 200 : 404, $this->api('GET', "devices/{$id}", cookie: $cookie), $identifier);
            $this->assertStatus($sees ? 200 : 404, $this->api('GET', "devices/{$id}/events", cookie: $cookie), $identifier);
        }

        $summary = $this->get('device-summary', $cookie);
        self::assertSame(count($visible), $summary['devices']['total']);
        self::assertSame(count($visible), $summary['inspection']['overdue']);
        self::assertSame(count(array_intersect(['M-G1', 'M-L'], $visible)), $summary['notices']['open']);
        self::assertSame($visible, self::sorted(self::identifiers($this->get('inspection-due', $cookie)['overdue']['devices'])));

        $history = $this->get('device-events?limit=200', $cookie);
        self::assertSame($visible, self::sorted(array_unique(array_map(static fn (array $e): string => $e['device']['identifier'], $history['events']))));
        self::assertSame(count($history['events']), $history['total']);

        self::assertSame($visible, self::sorted(array_column($this->csv('devices/export', $cookie), 0)));
        self::assertSame($visible, self::sorted(array_unique(array_column($this->csv('device-events/export', $cookie), 3))));

        $notices = $this->get('notices?status=all', $cookie);
        $expectedNotices = array_values(array_intersect(['M-G1', 'M-L'], $visible));
        self::assertSame($expectedNotices, self::sorted(array_map(static fn (array $n): string => $n['device']['identifier'], $notices['notices'])));
        self::assertSame(count($expectedNotices), $notices['total']);
        foreach ($this->notices as $identifier => $noticeId) {
            $this->assertStatus(in_array($identifier, $visible, true) ? 200 : 404, $this->api('GET', "notices/{$noticeId}", cookie: $cookie), $identifier);
        }
    }

    #[DataProvider('roles')]
    public function testLocationsAndDeviceTypesOnlyInFilterWithCountsOfVisibleDevices(string $role): void
    {
        $cookie = $this->cookie($role);
        $tree = $this->get('locations', $cookie)['locations'];
        $types = $this->get('device-types', $cookie)['device_types'];
        $counts = array_column($types, 'device_count', 'name');
        $locations = [];
        foreach ($tree as $location) {
            $locations[$location['name']] = [$location['device_count_total'], array_column($location['children'], 'device_count', 'name')];
            self::assertTrue($location['selectable']);
        }

        $expected = match ($role) {
            'admin' => [['HLF 20' => [1 + 1, ['G1' => 2, 'G2' => 0]], 'Lager' => [2, []], 'RTW' => [2, []]], ['Atemschutzmaske' => 3, 'Funkgerät' => 3]],
            'standort' => [['HLF 20' => [2, ['G1' => 2, 'G2' => 0]]], ['Atemschutzmaske' => 1, 'Funkgerät' => 1]],
            'typ' => [['HLF 20' => [1, ['G1' => 1, 'G2' => 0]], 'Lager' => [1, []], 'RTW' => [1, []]], ['Atemschutzmaske' => 3]],
            'beide' => [['HLF 20' => [1, ['G1' => 1, 'G2' => 0]]], ['Atemschutzmaske' => 1]],
        };
        self::assertSame($expected[0], $locations);
        self::assertSame($expected[1], $counts);

        $this->assertStatus($role === 'standort' || $role === 'beide' ? 404 : 200, $this->api('GET', "locations/{$this->room}", cookie: $cookie));
        $this->assertStatus(200, $this->api('GET', "locations/{$this->g1}", cookie: $cookie));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function filteredRoles(): iterable
    {
        yield 'standort' => ['standort'];
        yield 'typ' => ['typ'];
        yield 'beide' => ['beide'];
    }

    #[DataProvider('filteredRoles')]
    public function testWritesOnlyOnVisibleDevicesAndTargets(string $role): void
    {
        $cookie = $this->cookie($role);
        $hidden = array_values(array_diff(self::ALL, self::VISIBLE[$role]))[0];
        $hiddenId = $this->devices[$hidden];
        $visibleId = $this->devices['M-G1'];
        $snapshot = $this->deviceSnapshot();

        $this->assertError(404, 'not_found', $this->api('POST', "devices/{$hiddenId}/state", ['version' => 1, 'status' => 'defective', 'comment' => 'kaputt'], $cookie));
        $this->assertError(404, 'not_found', $this->api('POST', "devices/{$hiddenId}/fields", ['version' => 1, 'model' => 'X'], $cookie));
        $this->assertError(404, 'not_found', $this->api('POST', "devices/{$hiddenId}/inspection-due", ['version' => 1, 'next_inspection_due' => null], $cookie));
        $this->assertError(404, 'not_found', $this->api('POST', "devices/{$hiddenId}/retire", ['version' => 1], $cookie));
        $version = self::json($this->api('GET', "devices/{$visibleId}", cookie: $cookie))['device']['version'];
        if ($role !== 'typ') {
            $this->assertError(400, 'invalid_request', $this->api('POST', "devices/{$visibleId}/state", ['version' => $version, 'location_id' => $this->rtw], $cookie));
            $this->assertError(400, 'invalid_request', $this->api('POST', 'devices', ['identifier' => 'NEU-1', 'device_type_id' => $this->typeId, 'location_id' => $this->rtw], $cookie));
        }
        if ($role !== 'standort') {
            $this->assertError(400, 'invalid_request', $this->api('POST', "devices/{$visibleId}/fields", ['version' => $version, 'device_type_id' => $this->radioId], $cookie));
            $this->assertError(400, 'invalid_request', $this->api('POST', 'devices', ['identifier' => 'NEU-1', 'device_type_id' => $this->radioId, 'location_id' => $this->g1], $cookie));
        }

        $bulk = self::json($this->api('POST', 'devices/bulk/state', ['devices' => [
            ['id' => $hiddenId, 'version' => 1, 'status' => 'maintenance'],
        ]], $cookie));
        self::assertSame('error', $bulk['results'][0]['outcome']);
        self::assertSame('not_found', $bulk['results'][0]['error']['error']);
        self::assertNull($bulk['results'][0]['device'], 'kein Stand eines unsichtbaren Geräts');
        self::assertSame($snapshot, $this->deviceSnapshot(), 'nichts geschrieben');

        // Innerhalb des Filters geht alles wie bisher.
        $this->assertStatus(200, $this->api('POST', "devices/{$visibleId}/state", ['version' => $version, 'location_id' => $this->g2], $cookie));
        $this->assertStatus(201, $this->api('POST', 'devices', ['identifier' => 'NEU-2', 'device_type_id' => $this->typeId, 'location_id' => $this->g1], $cookie));
    }

    public function testLocationsOutsideTheFilterAreShownAsOtherLocation(): void
    {
        $cookie = $this->cookie('standort');
        $other = ['id' => null, 'name' => 'anderer Standort', 'path' => null];
        $id = $this->devices['M-G1'];

        $events = $this->get("devices/{$id}/events", $cookie)['events'];
        $moved = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'state_changed' && $e['applied']))[0];
        self::assertSame(['from' => $other, 'to' => ['id' => $this->g1, 'name' => 'G1', 'path' => 'HLF 20 / G1']], $moved['location']);
        $created = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'created'))[0];
        self::assertSame(['from' => null, 'to' => $other], $created['location']);
        $notApplied = array_values(array_filter($events, static fn (array $e): bool => !$e['applied']))[0];
        self::assertSame($other, $notApplied['location']['to']);

        $history = $this->get('device-events?limit=200', $cookie)['events'];
        self::assertContains($other, array_merge(...array_map(static fn (array $e): array => array_values($e['location'] ?? []), $history)));
        self::assertNotContains('Lager', array_column(array_merge(...array_map(static fn (array $e): array => array_values(array_filter($e['location'] ?? [])), $history)), 'name'));

        $notice = self::json($this->api('GET', 'notices/' . $this->notices['M-G1'], cookie: $cookie))['notice'];
        self::assertSame($other, $notice['booking']['location']);
        self::assertSame(['id' => $this->g1, 'name' => 'G1', 'path' => 'HLF 20 / G1'], $notice['other']['location']);

        $rows = $this->csv('device-events/export', $cookie);
        $locations = array_merge(array_column($rows, 7), array_column($rows, 8));
        self::assertContains('anderer Standort', $locations);
        self::assertNotContains('Lager', $locations);

        // Der Admin sieht die echten Standorte.
        $notice = self::json($this->api('GET', 'notices/' . $this->notices['M-G1']))['notice'];
        self::assertSame(['id' => $this->room, 'name' => 'Lager', 'path' => 'Lager'], $notice['booking']['location']);
    }

    public function testDeviceMovedOutOfTheFilterIsNotFoundEvenWithAnOldVersion(): void
    {
        $cookie = $this->cookie('standort');
        $id = $this->devices['F-G1'];
        $this->assertStatus(200, $this->api('POST', "devices/{$id}/state", ['version' => 1, 'location_id' => $this->rtw]));

        // Kein version_conflict mit details.device (das den Standort zeigte), sondern wie fremd.
        $response = $this->api('POST', "devices/{$id}/state", ['version' => 1, 'status' => 'maintenance'], $cookie);
        $this->assertError(404, 'not_found', $response);
        self::assertStringNotContainsString('RTW', $response->body());
        $response = $this->api('POST', "devices/{$id}/inspection-due", ['version' => 1, 'next_inspection_due' => null], $cookie);
        $this->assertError(404, 'not_found', $response);
        self::assertStringNotContainsString('details', $response->body());
    }

    public function testReplayedClientRefOfADeviceNoLongerVisibleIsNotFound(): void
    {
        $cookie = $this->cookie('standort');
        $id = $this->devices['F-G1'];
        $body = ['version' => 1, 'status' => 'maintenance', 'client_ref' => $this->ref()];
        $first = self::json($this->api('POST', "devices/{$id}/state", $body, $cookie));
        self::assertSame('applied', $first['result']['outcome']);
        $replay = self::json($this->api('POST', "devices/{$id}/state", $body, $cookie));
        self::assertTrue($replay['result']['replayed']);

        $this->assertStatus(200, $this->api('POST', "devices/{$id}/state", ['version' => 2, 'location_id' => $this->rtw]));

        $response = $this->api('POST', "devices/{$id}/state", $body, $cookie);
        $this->assertError(404, 'not_found', $response);
        self::assertStringNotContainsString('RTW', $response->body());
        // Ebenso offline (mit Aktionszeit) und in der Sammelaktion.
        $offline = ['version' => 2, 'status' => 'defective', 'comment' => 'Akku defekt', 'occurred_at' => $this->at('-10 minutes'), 'client_ref' => $this->ref()];
        $this->assertError(404, 'not_found', $this->offlineResponse($id, $offline, $cookie));
        $bulk = self::json($this->api('POST', 'devices/bulk/state', ['devices' => [['id' => $id, 'version' => 1, 'status' => 'maintenance', 'client_ref' => $body['client_ref']]]], $cookie));
        self::assertSame(['error', 'not_found', null], [$bulk['results'][0]['outcome'], $bulk['results'][0]['error']['error'], $bulk['results'][0]['device']]);
    }

    /**
     * @return iterable<string, array{array<string, int>, bool, bool}>
     */
    public static function emptyFilters(): iterable
    {
        yield 'Standortfilter ohne Standorte' => [['filter_locations' => 1], false, true];
        yield 'Typfilter ohne Typen' => [['filter_device_types' => 1], true, false];
        yield 'beide ohne Auswahl' => [['filter_locations' => 1, 'filter_device_types' => 1], false, false];
    }

    /**
     * Eingeschalteter Filter ohne Auswahl: Die Rolle sieht nichts (der Editor in Schritt 1.5 lässt
     * das nicht speichern).
     *
     * @param array<string, int> $flags
     */
    #[DataProvider('emptyFilters')]
    public function testFilterWithoutSelectionShowsNothing(array $flags, bool $locationsListed, bool $typesListed): void
    {
        $cookie = $this->roleCookie($flags, [], []);

        $list = $this->get('devices', $cookie);
        self::assertSame([[], 0], [$list['devices'], $list['total']]);
        self::assertSame(0, $this->get('device-summary', $cookie)['devices']['total']);
        self::assertSame([], $this->get('device-events', $cookie)['events']);
        self::assertSame([], $this->get('notices', $cookie)['notices']);
        self::assertSame([], $this->csv('devices/export', $cookie));
        self::assertSame($locationsListed ? 3 : 0, count($this->get('locations', $cookie)['locations']));
        self::assertSame($typesListed ? 2 : 0, count($this->get('device-types', $cookie)['device_types']));
        foreach ($this->devices as $id) {
            $this->assertStatus(404, $this->api('GET', "devices/{$id}", cookie: $cookie));
        }
        $this->assertError(400, 'invalid_request', $this->api('POST', 'devices', ['identifier' => 'NEU-1', 'device_type_id' => $this->typeId, 'location_id' => $this->g1], $cookie));
        self::assertTrue(self::json($this->api('GET', 'auth/me', cookie: $cookie))['permissions']['filtered']);
    }

    /**
     * Nur ein Fach gewählt: Der Standort der Ebene 1 erscheint mit diesem Fach, aber nicht wählbar
     * und ohne Gerätezahl; Buchen auf ihn selbst geht nicht. Ein neues Fach eines gewählten
     * Standorts der Ebene 1 gehört sofort dazu.
     */
    public function testOnlyOneCompartmentAndNewCompartmentsOfASelectedLocation(): void
    {
        $cookie = $this->roleCookie(['filter_locations' => 1], [$this->g1], []);
        $tree = $this->get('locations', $cookie)['locations'];
        self::assertCount(1, $tree);
        self::assertSame(['HLF 20', false, null, 2], [$tree[0]['name'], $tree[0]['selectable'], $tree[0]['device_count'], $tree[0]['device_count_total']]);
        self::assertSame(['G1'], array_column($tree[0]['children'], 'name'));
        self::assertTrue($tree[0]['children'][0]['selectable']);
        $id = $this->devices['F-G1'];
        $this->assertError(400, 'invalid_request', $this->api('POST', "devices/{$id}/state", ['version' => 1, 'location_id' => $this->hlf], $cookie));

        $hlfCookie = $this->roleCookie(['filter_locations' => 1], [$this->hlf], []);
        $versionBefore = self::json($this->api('GET', 'auth/me', cookie: $hlfCookie))['permissions']['version'];
        $g3 = $this->createSubLocation($this->tenantId, $this->hlf, ['name' => 'G3']);
        self::assertSame(['G1', 'G2', 'G3'], array_column($this->get('locations', $hlfCookie)['locations'][0]['children'], 'name'));
        $this->assertStatus(200, $this->api('POST', "devices/{$id}/state", ['version' => 1, 'location_id' => $g3], $hlfCookie));
        self::assertNotSame($versionBefore, self::json($this->api('GET', 'auth/me', cookie: $hlfCookie))['permissions']['version']);
    }

    /**
     * F3: Mit Datenfilter wirken Benutzer, Standorte und Gerätetypen Ändern sowie der CSV-Import
     * nicht, auch wenn die Rolle sie in der Datenbank hat.
     */
    public function testRolesWithFilterCannotAdministrate(): void
    {
        $cookie = $this->roleCookie(['filter_locations' => 1], [$this->hlf], [], ['users' => 'write', 'locations' => 'write', 'device_types' => 'write'], ['csv.import']);

        $this->assertError(403, 'forbidden', $this->api('GET', 'users', cookie: $cookie));
        $this->assertError(403, 'forbidden', $this->api('POST', 'locations', ['name' => 'Neu', 'kind' => 'room'], $cookie));
        $this->assertError(403, 'forbidden', $this->api('POST', 'device-types', ['name' => 'Neu'], $cookie));
        $this->assertError(403, 'forbidden', $this->api('GET', 'devices/import/template', cookie: $cookie));
        $this->assertStatus(200, $this->api('GET', 'locations', cookie: $cookie));
        $permissions = self::json($this->api('GET', 'auth/me', cookie: $cookie))['permissions'];
        self::assertArrayNotHasKey('users', $permissions['areas']);
        self::assertSame('read', $permissions['areas']['locations']);
        self::assertSame('read', $permissions['areas']['device_types']);
        self::assertNotContains('csv.import', $permissions['actions']);
    }

    /**
     * Konto mit einer der Rollen des Matrix-Tests (Admin: die Standard-Rolle).
     */
    private function cookie(string $role): string
    {
        return match ($role) {
            'admin' => $this->admin,
            'standort' => $this->roleCookie(['filter_locations' => 1], [$this->hlf], []),
            'typ' => $this->roleCookie(['filter_device_types' => 1], [], [$this->typeId]),
            'beide' => $this->roleCookie(['filter_locations' => 1, 'filter_device_types' => 1], [$this->hlf], [$this->typeId]),
        };
    }

    /**
     * Angemeldetes Konto mit Filterrolle (FilteredRoles).
     *
     * @param array<string, int>    $flags         filter_locations, filter_device_types
     * @param list<int>             $locationIds
     * @param list<int>             $deviceTypeIds
     * @param array<string, string> $extraAreas
     * @param list<string>          $extraActions
     */
    private function roleCookie(array $flags, array $locationIds, array $deviceTypeIds, array $extraAreas = [], array $extraActions = []): string
    {
        $email = sprintf('filter%d@example.org', ++$this->accountCounter);
        $this->filteredAccount($email, $flags, $locationIds, $deviceTypeIds, $extraAreas, $extraActions);

        return $this->loginCookie('immendingen', $email);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path, string $cookie): array
    {
        $response = $this->api('GET', $path, cookie: $cookie);
        self::assertSame(200, $response->statusCode(), $path . ': ' . $response->body());

        return self::json($response);
    }

    /**
     * @return list<list<string>> Datenzeilen ohne Kopfzeile
     */
    private function csv(string $path, string $cookie): array
    {
        $response = $this->sendAny('GET', self::url('immendingen', $path), cookie: $cookie);
        self::assertSame(200, $response->statusCode());
        $body = self::responseBody($response);
        self::assertStringStartsWith(CsvFormat::BOM, $body);
        $lines = array_values(array_filter(explode("\r\n", substr($body, strlen(CsvFormat::BOM))), static fn (string $l): bool => $l !== ''));

        return array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), array_slice($lines, 1));
    }

    private function assertStatus(int $status, \FirefightingEquipment\Http\JsonResponse $response, string $message = ''): void
    {
        self::assertSame($status, $response->statusCode(), $message . ' ' . $response->body());
    }

    /**
     * @param list<array<string, mixed>> $devices
     *
     * @return list<string>
     */
    private static function identifiers(array $devices): array
    {
        return array_column($devices, 'identifier');
    }

    /**
     * @param array<array-key, string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values($values);
        sort($values);

        return $values;
    }
}
