<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceListHandler;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceSort;
use FirefightingEquipment\Device\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Sortierung von GET devices (sort, dir): je Spalte und Richtung, natürlich und ohne
 * Groß-/Kleinschreibung bei Texten, Status in fachlicher Reihenfolge, Geräte ohne Prüfdatum immer
 * am Ende, Gleichstand nach Kennung und ID, stabile Seiten.
 *
 * Geräte (alle mit include_retired=1):
 *   M10   Atemschutzmaske  HLF 20 / G1  ready        2027-01-01
 *   M2    Funkgerät 10     HLF 3        defective    –
 *   m3    atemschutzgerät  drehleiter   maintenance  2026-06-30
 *   M1    Funkgerät 2      Lager        ready        2027-01-01
 *   MA-1  Atemschutzmaske  HLF 20       retired      2026-01-15
 *   M4    Funkgerät 2      HLF 20 / G1  defective    –
 */
#[CoversClass(DeviceListHandler::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceSort::class)]
#[CoversClass(SortDirection::class)]
final class DeviceListSortTest extends DeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $radio10 = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät 10']);
        $radio2 = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät 2']);
        $breathing = $this->createDeviceType($this->tenantId, ['name' => 'atemschutzgerät']);
        $hlf3 = $this->createLocation($this->tenantId, ['name' => 'HLF 3']);
        $ladder = $this->createLocation($this->tenantId, ['name' => 'drehleiter']);

        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'M10', 'next_inspection_due' => '2027-01-01']);
        $this->createDevice($this->tenantId, $radio10, $hlf3, ['identifier' => 'M2', 'status' => 'defective']);
        $this->createDevice($this->tenantId, $breathing, $ladder, ['identifier' => 'm3', 'status' => 'maintenance', 'next_inspection_due' => '2026-06-30']);
        $this->createDevice($this->tenantId, $radio2, $this->room, ['identifier' => 'M1', 'next_inspection_due' => '2027-01-01']);
        $this->createDevice($this->tenantId, $this->typeId, $this->hlf, ['identifier' => 'MA-1', 'status' => 'retired', 'next_inspection_due' => '2026-01-15']);
        $this->createDevice($this->tenantId, $radio2, $this->g1, ['identifier' => 'M4', 'status' => 'defective']);
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'M0']);
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function sortings(): iterable
    {
        yield 'Kennung aufsteigend' => ['identifier', 'asc', ['M1', 'M2', 'm3', 'M4', 'M10', 'MA-1']];
        yield 'Kennung absteigend' => ['identifier', 'desc', ['MA-1', 'M10', 'M4', 'm3', 'M2', 'M1']];
        yield 'Gerätetyp aufsteigend' => ['device_type', 'asc', ['m3', 'M10', 'MA-1', 'M1', 'M4', 'M2']];
        yield 'Gerätetyp absteigend' => ['device_type', 'desc', ['M2', 'M1', 'M4', 'M10', 'MA-1', 'm3']];
        yield 'Standort aufsteigend' => ['location', 'asc', ['m3', 'M2', 'MA-1', 'M4', 'M10', 'M1']];
        yield 'Standort absteigend' => ['location', 'desc', ['M1', 'M4', 'M10', 'MA-1', 'M2', 'm3']];
        yield 'Status aufsteigend' => ['status', 'asc', ['M1', 'M10', 'm3', 'M2', 'M4', 'MA-1']];
        yield 'Status absteigend' => ['status', 'desc', ['MA-1', 'M2', 'M4', 'm3', 'M1', 'M10']];
        yield 'Prüfdatum aufsteigend' => ['next_inspection_due', 'asc', ['MA-1', 'm3', 'M1', 'M10', 'M2', 'M4']];
        yield 'Prüfdatum absteigend' => ['next_inspection_due', 'desc', ['M1', 'M10', 'm3', 'MA-1', 'M2', 'M4']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('sortings')]
    public function testSortByColumnAndDirection(string $sort, string $dir, array $expected): void
    {
        self::assertSame($expected, $this->identifiers(sprintf('devices?include_retired=1&sort=%s&dir=%s', $sort, $dir)));
    }

    /**
     * Alle Seiten mit limit=2 hintereinander ergeben genau die ganze Liste (keine Lücken, keine
     * Doppelten), auch bei Gleichstand in der sortierten Spalte.
     *
     * @param list<string> $expected
     */
    #[DataProvider('sortings')]
    public function testPagesAreStable(string $sort, string $dir, array $expected): void
    {
        $pages = [];
        for ($offset = 0; $offset < 8; $offset += 2) {
            $page = self::json($this->api('GET', sprintf('devices?include_retired=1&sort=%s&dir=%s&limit=2&offset=%d', $sort, $dir, $offset)));
            self::assertSame(6, $page['total']);
            $pages = [...$pages, ...array_column($page['devices'], 'identifier')];
        }

        self::assertSame($expected, $pages);
    }

    public function testDefaultsAreIdentifierAscending(): void
    {
        self::assertSame(['M1', 'M2', 'm3', 'M4', 'M10'], $this->identifiers('devices'));
        self::assertSame(['M10', 'M4', 'm3', 'M2', 'M1'], $this->identifiers('devices?dir=desc'));
        self::assertSame(['M1', 'M10', 'm3', 'M2', 'M4'], $this->identifiers('devices?sort=status'));
    }

    public function testWithoutInspectionDateLastInBothDirections(): void
    {
        foreach (['asc', 'desc'] as $dir) {
            $json = self::json($this->api('GET', 'devices?sort=next_inspection_due&dir=' . $dir, cookie: $this->user));
            $dates = array_column($json['devices'], 'next_inspection_due');

            self::assertSame([null, null], array_slice($dates, -2), $dir);
            self::assertNotContains(null, array_slice($dates, 0, -2), $dir);
        }
    }

    public function testSortCombinesWithFilter(): void
    {
        self::assertSame(
            ['MA-1', 'M4', 'M10'],
            $this->identifiers(sprintf('devices?location_id=%d&include_sublocations=1&include_retired=1&sort=status&dir=desc', $this->hlf)),
        );
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
