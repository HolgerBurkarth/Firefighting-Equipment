<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceActionHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceJson;
use FirefightingEquipment\Api\V1\Devices\DeviceListHandler;
use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceFilter;
use FirefightingEquipment\Device\DevicePage;
use FirefightingEquipment\Device\DeviceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET devices und GET devices/{id} (angemeldet, auch Benutzer): Filter, natürliche Sortierung,
 * Sortierung nach fälliger Prüfung, Seiten mit Gesamtzahl, Mandantentrennung.
 */
#[CoversClass(DeviceListHandler::class)]
#[CoversClass(DeviceActionHandler::class)]
#[CoversClass(DeviceJson::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceFilter::class)]
#[CoversClass(DevicePage::class)]
#[CoversClass(Device::class)]
final class DeviceListTest extends DeviceApiTestCase
{
    public function testUserSeesSummariesSortedNaturally(): void
    {
        foreach (['M10', 'M2', 'm3', 'M1', 'MA-1'] as $identifier) {
            $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => $identifier]);
        }
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'M0']);

        $response = $this->api('GET', 'devices', cookie: $this->user);

        self::assertSame(200, $response->statusCode(), $response->body());
        $json = self::json($response);
        self::assertSame(['devices', 'total', 'limit', 'offset'], array_keys($json));
        self::assertSame([5, 50, 0], [$json['total'], $json['limit'], $json['offset']]);
        self::assertSame(['M1', 'M2', 'm3', 'M10', 'MA-1'], array_column($json['devices'], 'identifier'));
        self::assertSame(
            ['id', 'identifier', 'scan_code', 'device_type', 'location', 'status', 'status_label', 'next_inspection_due', 'version'],
            array_keys($json['devices'][0]),
        );
        self::assertSame(['id' => $this->g1, 'name' => 'G1', 'path' => 'HLF 20 / G1'], $json['devices'][0]['location']);
        self::assertSame(['id' => $this->typeId, 'name' => 'Atemschutzmaske'], $json['devices'][0]['device_type']);
    }

    public function testRetiredAreHiddenUnlessRequested(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'A', 'status' => 'ready']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'B', 'status' => 'defective']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'C', 'status' => 'retired']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'D', 'status' => 'maintenance']);

        self::assertSame(['A', 'B', 'D'], $this->identifiers('devices'));
        self::assertSame(['A', 'B', 'C', 'D'], $this->identifiers('devices?include_retired=1'));
        self::assertSame(['B', 'D'], $this->identifiers('devices?status=maintenance,defective'));
        self::assertSame(['C'], $this->identifiers('devices?status=retired'));
        self::assertSame(['A', 'C'], $this->identifiers('devices?status=retired,ready'));
        self::assertSame(['A'], $this->identifiers('devices?status=ready&include_retired=1'));
    }

    public function testFilterByTypeAndLocation(): void
    {
        $radio = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $g2 = $this->createSubLocation($this->tenantId, $this->hlf, ['name' => 'G2']);
        $this->createDevice($this->tenantId, $this->typeId, $this->hlf, ['identifier' => 'A']);
        $this->createDevice($this->tenantId, $radio, $this->g1, ['identifier' => 'B']);
        $this->createDevice($this->tenantId, $this->typeId, $g2, ['identifier' => 'C']);
        $this->createDevice($this->tenantId, $this->typeId, $this->room, ['identifier' => 'D']);

        self::assertSame(['B'], $this->identifiers('devices?device_type_id=' . $radio));
        self::assertSame(['A'], $this->identifiers('devices?location_id=' . $this->hlf));
        self::assertSame(['A', 'B', 'C'], $this->identifiers('devices?location_id=' . $this->hlf . '&include_sublocations=1'));
        self::assertSame(['B'], $this->identifiers('devices?location_id=' . $this->g1 . '&include_sublocations=1'));
        self::assertSame(['C'], $this->identifiers('devices?location_id=' . $this->hlf . '&include_sublocations=1&device_type_id=' . $this->typeId . '&q=c'));
    }

    public function testUnknownOrForeignIdsGiveEmptyList(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->g1);
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation);

        foreach ([
            'devices?device_type_id=' . $this->otherTypeId,
            'devices?location_id=' . $this->otherLocation,
            'devices?location_id=' . $this->otherLocation . '&include_sublocations=1',
            'devices?device_type_id=999999999',
        ] as $path) {
            $json = self::json($this->api('GET', $path));
            self::assertSame([[], 0], [$json['devices'], $json['total']], $path);
        }
    }

    public function testSearchInIdentifierSerialManufacturerAndModel(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'X1', 'serial_number' => 'ARFK-55']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'X2', 'manufacturer' => 'Dräger']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'X3', 'model' => 'FPS 7000']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'X4', 'note' => 'ma-01 im Text']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'P1', 'model' => '100%', 'manufacturer' => 'a_b\\c']);

        self::assertSame(['MA-01'], $this->identifiers('devices?q=ma-0'));
        self::assertSame(['X1'], $this->identifiers('devices?q=arfk'));
        self::assertSame(['X2'], $this->identifiers('devices?q=DRÄG'));
        self::assertSame(['X3'], $this->identifiers('devices?q=' . rawurlencode(' fps 7 ')));
        self::assertSame(['MA-01', 'P1', 'X1', 'X2', 'X3', 'X4'], $this->identifiers('devices?q=' . rawurlencode('  ')));
        // % und _ sind keine Platzhalter, \ ist kein Escape-Zeichen.
        self::assertSame(['P1'], $this->identifiers('devices?q=%25'));
        self::assertSame([], $this->identifiers('devices?q=%25%25'));
        self::assertSame(['P1'], $this->identifiers('devices?q=a_b'));
        self::assertSame([], $this->identifiers('devices?q=X_'));
        self::assertSame(['P1'], $this->identifiers('devices?q=' . rawurlencode('b\\c')));
        self::assertSame([], $this->identifiers('devices?q=' . rawurlencode('\\_')));
    }

    public function testSortByNextInspectionDueWithEmptyLast(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'M10', 'next_inspection_due' => '2027-01-01']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'M2', 'next_inspection_due' => '2027-01-01']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'M1']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'M3', 'next_inspection_due' => '2026-06-30']);

        $json = self::json($this->api('GET', 'devices?sort=next_inspection_due'));

        self::assertSame(['M3', 'M2', 'M10', 'M1'], array_column($json['devices'], 'identifier'));
        self::assertSame(['2026-06-30', '2027-01-01', '2027-01-01', null], array_column($json['devices'], 'next_inspection_due'));
        self::assertSame(['M1', 'M2', 'M3', 'M10'], $this->identifiers('devices?sort=identifier'));
    }

    public function testPagesWithTotal(): void
    {
        for ($i = 1; $i <= 7; ++$i) {
            $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'M' . $i]);
        }

        $page = self::json($this->api('GET', 'devices?limit=3&offset=3'));
        self::assertSame([['M4', 'M5', 'M6'], 7, 3, 3], [array_column($page['devices'], 'identifier'), $page['total'], $page['limit'], $page['offset']]);
        $last = self::json($this->api('GET', 'devices?limit=3&offset=6'));
        self::assertSame([['M7'], 7], [array_column($last['devices'], 'identifier'), $last['total']]);
        $beyond = self::json($this->api('GET', 'devices?limit=3&offset=100'));
        self::assertSame([[], 7], [$beyond['devices'], $beyond['total']]);
        self::assertSame(200, self::json($this->api('GET', 'devices?limit=200'))['limit']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'limit zu groß' => ['limit=201', 'Der Parameter "limit" muss eine ganze Zahl von 1 bis 200 sein.'];
        yield 'limit 0' => ['limit=0', 'Der Parameter "limit"'];
        yield 'offset negativ' => ['offset=-1', 'Der Parameter "offset"'];
        yield 'offset zu groß' => ['offset=1000001', 'Der Parameter "offset"'];
        yield 'Typ-ID kein Wert' => ['device_type_id=abc', 'Der Parameter "device_type_id"'];
        yield 'Standort-ID 0' => ['location_id=0', 'Der Parameter "location_id"'];
        yield 'Unterstandorte ohne Standort' => ['include_sublocations=1', 'nur zusammen mit einem Standort (location_id)'];
        yield 'Unterstandorte ungültig' => ['include_sublocations=ja', 'Der Parameter "include_sublocations" muss 0 oder 1 sein.'];
        yield 'Ausgesonderte ungültig' => ['include_retired=true', 'Der Parameter "include_retired" muss 0 oder 1 sein.'];
        yield 'Status unbekannt' => ['status=kaputt', 'Der Parameter "status" muss eine kommagetrennte Liste'];
        yield 'Status leer' => ['status=', 'Der Parameter "status"'];
        yield 'Status mit Leerzeichen' => ['status=ready,%20defective', 'Der Parameter "status"'];
        yield 'Sortierung unbekannt' => ['sort=name', 'Der Parameter "sort" muss einer der Werte "identifier", "device_type", "location", "status", "next_inspection_due" sein.'];
        yield 'Sortierung leer' => ['sort=', 'Der Parameter "sort"'];
        yield 'Sortierung groß' => ['sort=Identifier', 'Der Parameter "sort"'];
        yield 'Richtung unbekannt' => ['dir=up', 'Der Parameter "dir" muss "asc" oder "desc" sein.'];
        yield 'Richtung groß' => ['dir=DESC', 'Der Parameter "dir"'];
        yield 'Richtung leer' => ['dir=', 'Der Parameter "dir"'];
        yield 'Suche zu lang' => ['q=' . str_repeat('x', 101), 'Der Suchtext darf höchstens 100 Zeichen'];
        yield 'Suche mit Steuerzeichen' => ['q=a%0Ab', 'Der Suchtext darf keine Steuerzeichen'];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryIsRejected(string $query, string $message): void
    {
        $response = $this->api('GET', 'devices?' . $query);

        self::assertError(400, 'invalid_request', $response);
        self::assertStringContainsString($message, self::json($response)['message']);
    }

    public function testShowReturnsAllFieldsAlsoForRetired(): void
    {
        $id = $this->createDevice($this->tenantId, $this->typeId, $this->hlf, [
            'identifier' => 'MA-01', 'status' => 'retired', 'serial_number' => 'S-1', 'manufacturer' => 'Dräger', 'model' => 'FPS',
            'procured_on' => '2020-02-01', 'next_inspection_due' => '2026-12-31', 'note' => "a\nb", 'version' => 4,
        ]);

        $response = $this->api('GET', 'devices/' . $id, cookie: $this->user);

        self::assertSame(200, $response->statusCode(), $response->body());
        $device = self::json($response)['device'];
        self::assertSame(
            [$id, 'MA-01', 'retired', 'ausgesondert', 'S-1', 'Dräger', 'FPS', '2020-02-01', '2026-12-31', "a\nb", 4, 'HLF 20'],
            [
                $device['id'], $device['identifier'], $device['status'], $device['status_label'], $device['serial_number'],
                $device['manufacturer'], $device['model'], $device['procured_on'], $device['next_inspection_due'], $device['note'],
                $device['version'], $device['location']['path'],
            ],
        );
        self::assertError(404, 'not_found', $this->api('GET', 'devices/999999999'));
    }

    /**
     * @return list<string>
     */
    private function identifiers(string $path): array
    {
        $response = $this->api('GET', $path);
        self::assertSame(200, $response->statusCode(), $response->body());

        return array_column(self::json($response)['devices'], 'identifier');
    }
}
