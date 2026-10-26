<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Inspection;

use FirefightingEquipment\Api\V1\Inspections\InspectionDueHandler;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\InspectionSection;
use FirefightingEquipment\Device\InspectionWindow;
use FirefightingEquipment\Time\LocalDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET inspection-due (angemeldet): Abschnitte überfällig, bald fällig und ohne Prüfdatum, „heute“
 * nach deutscher Zeit, Grenzen, Sortierung, Filter, nie ausgesonderte Geräte, Mandantentrennung.
 * Rechte: TenantReadRoutesAccessTest.
 */
#[CoversClass(InspectionDueHandler::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(InspectionWindow::class)]
#[CoversClass(InspectionSection::class)]
#[CoversClass(LocalDate::class)]
final class InspectionDueTest extends InspectionApiTestCase
{
    public function testSectionsAndBoundaries(): void
    {
        $this->deviceDue('Gestern', '2026-06-14');
        $this->deviceDue('Heute', '2026-06-15');
        $this->deviceDue('In 30 Tagen', '2026-07-15');
        $this->deviceDue('In 31 Tagen', '2026-07-16');
        $this->deviceDue('Lange her', '2026-05-01');
        $this->deviceDue('Ohne', null);

        $json = $this->get('inspection-due');

        self::assertSame(['today', 'days', 'until', 'overdue', 'due_soon'], array_keys($json));
        self::assertSame([self::TODAY, 30, '2026-07-15'], [$json['today'], $json['days'], $json['until']]);
        self::assertSame(['devices', 'total'], array_keys($json['overdue']));
        self::assertSame([['Lange her', 45], ['Gestern', 1]], self::rows($json['overdue'], 'days_overdue'));
        self::assertSame(2, $json['overdue']['total']);
        self::assertSame([['Heute', 0], ['In 30 Tagen', 30]], self::rows($json['due_soon'], 'days_until_due'));
        self::assertSame(2, $json['due_soon']['total']);

        $device = $json['overdue']['devices'][1];
        self::assertSame(
            ['id', 'identifier', 'device_type', 'location', 'status', 'status_label', 'next_inspection_due', 'version', 'days_overdue'],
            array_keys($device),
        );
        self::assertSame(['id' => $this->g1, 'name' => 'G1', 'path' => 'HLF 20 / G1'], $device['location']);
        self::assertSame(['id' => $this->typeId, 'name' => 'Atemschutzmaske'], $device['device_type']);
        self::assertSame('2026-06-14', $device['next_inspection_due']);
        self::assertArrayNotHasKey('days_overdue', $json['due_soon']['devices'][0]);
        self::assertArrayHasKey('days_until_due', $json['due_soon']['devices'][0]);
    }

    public function testDaysParameter(): void
    {
        $this->deviceDue('Heute', '2026-06-15');
        $this->deviceDue('Morgen', '2026-06-16');
        $this->deviceDue('In 31 Tagen', '2026-07-16');
        $this->deviceDue('In 365 Tagen', '2027-06-15');
        $this->deviceDue('In 366 Tagen', '2027-06-16');

        self::assertSame(['Heute', 'Morgen'], self::identifiers($this->get('inspection-due?days=1')['due_soon']));
        self::assertSame(['Heute', 'Morgen', 'In 31 Tagen'], self::identifiers($this->get('inspection-due?days=31')['due_soon']));
        $json = $this->get('inspection-due?days=365');
        self::assertSame(['Heute', 'Morgen', 'In 31 Tagen', 'In 365 Tagen'], self::identifiers($json['due_soon']));
        self::assertSame([365, '2027-06-15'], [$json['days'], $json['until']]);

        foreach (['0', '366', 'abc', '01', '-1', ''] as $days) {
            self::assertError(400, 'invalid_request', $this->api('GET', 'inspection-due?days=' . $days, cookie: $this->user));
        }
    }

    public function testWithoutDateOnRequestSortedNaturally(): void
    {
        $this->deviceDue('M10', null);
        $this->deviceDue('M2', null);
        $this->deviceDue('M1', '2026-06-20');

        $json = $this->get('inspection-due?include_without_date=1');

        self::assertSame(['today', 'days', 'until', 'overdue', 'due_soon', 'without_date'], array_keys($json));
        self::assertSame(['M2', 'M10'], self::identifiers($json['without_date']));
        self::assertSame(2, $json['without_date']['total']);
        self::assertSame(
            ['id', 'identifier', 'device_type', 'location', 'status', 'status_label', 'next_inspection_due', 'version'],
            array_keys($json['without_date']['devices'][0]),
        );
        self::assertArrayNotHasKey('without_date', $this->get('inspection-due?include_without_date=0'));
        self::assertError(400, 'invalid_request', $this->api('GET', 'inspection-due?include_without_date=2', cookie: $this->user));
    }

    public function testSortedByDueDateThenNaturallyByIdentifier(): void
    {
        $this->deviceDue('M10', '2026-06-01');
        $this->deviceDue('M2', '2026-06-01');
        $this->deviceDue('M3', '2026-06-10');
        $this->deviceDue('M1', '2026-05-20');
        $this->deviceDue('S10', '2026-06-20');
        $this->deviceDue('S9', '2026-06-20');
        $this->deviceDue('S1', '2026-06-18');

        $json = $this->get('inspection-due');

        self::assertSame(['M1', 'M2', 'M10', 'M3'], self::identifiers($json['overdue']));
        self::assertSame(['S1', 'S9', 'S10'], self::identifiers($json['due_soon']));
    }

    public function testRetiredNeverIncludedOtherStatusesAre(): void
    {
        $this->deviceDue('Defekt', '2026-06-01', ['status' => 'defective']);
        $this->deviceDue('Wartung', '2026-06-20', ['status' => 'maintenance']);
        $this->deviceDue('Weg-1', '2026-06-01', ['status' => 'retired']);
        $this->deviceDue('Weg-2', '2026-06-20', ['status' => 'retired']);
        $this->deviceDue('Weg-3', null, ['status' => 'retired']);

        // Parameter der Geräteliste werden ignoriert und schließen Ausgesonderte nicht ein.
        foreach (['inspection-due?include_without_date=1', 'inspection-due?include_without_date=1&include_retired=1&status=retired'] as $path) {
            $json = $this->get($path);
            self::assertSame(['Defekt'], self::identifiers($json['overdue']), $path);
            self::assertSame('defective', $json['overdue']['devices'][0]['status']);
            self::assertSame(['Wartung'], self::identifiers($json['due_soon']), $path);
            self::assertSame([], $json['without_date']['devices'], $path);
            self::assertSame([1, 1, 0], [$json['overdue']['total'], $json['due_soon']['total'], $json['without_date']['total']]);
        }
    }

    public function testRetiredViaApiDisappears(): void
    {
        $device = $this->createViaApi('MA-1', ['next_inspection_due' => '2026-06-01']);
        self::assertSame(['MA-1'], self::identifiers($this->get('inspection-due')['overdue']));

        $response = $this->api('POST', 'devices/' . $device['id'] . '/retire', ['version' => $device['version']]);
        self::assertSame(200, $response->statusCode(), $response->body());

        self::assertSame([], $this->get('inspection-due')['overdue']['devices']);
    }

    public function testMidnightGermanTimeNotUtc(): void
    {
        $this->deviceDue('A', '2026-06-15');

        // 23:59:59 MESZ: noch der 15., heute fällig
        $this->clock->set('2026-06-15T21:59:59Z');
        $json = $this->get('inspection-due');
        self::assertSame(self::TODAY, $json['today']);
        self::assertSame([['A', 0]], self::rows($json['due_soon'], 'days_until_due'));

        // 00:30 MESZ am 16. (in UTC noch der 15.): überfällig
        $this->clock->set('2026-06-15T22:30:00Z');
        $json = $this->get('inspection-due');
        self::assertSame(['2026-06-16', '2026-07-16'], [$json['today'], $json['until']]);
        self::assertSame([['A', 1]], self::rows($json['overdue'], 'days_overdue'));
        self::assertSame([], $json['due_soon']['devices']);
    }

    /**
     * @return iterable<string, array{string, string, int}> Zeitpunkt (UTC), heute, Tage bis 2026-10-26
     */
    public static function daylightSavingTime(): iterable
    {
        yield 'Winterzeit: 23:30 MEZ' => ['2026-01-15T22:30:00Z', '2026-01-15', 284];
        yield 'Winterzeit: 00:00 MEZ' => ['2026-01-15T23:00:00Z', '2026-01-16', 283];
        yield 'März: Mitternacht MEZ vor der Umstellung' => ['2026-03-28T23:00:00Z', '2026-03-29', 211];
        yield 'März: 23:59 MESZ nach der Umstellung' => ['2026-03-29T21:59:00Z', '2026-03-29', 211];
        yield 'März: Mitternacht MESZ nach der Umstellung' => ['2026-03-29T22:00:00Z', '2026-03-30', 210];
        yield 'Oktober: Mitternacht MESZ vor der Umstellung' => ['2026-10-24T22:00:00Z', '2026-10-25', 1];
        yield 'Oktober: 23:30 MEZ nach der Umstellung' => ['2026-10-25T22:30:00Z', '2026-10-25', 1];
        yield 'Oktober: Mitternacht MEZ nach der Umstellung' => ['2026-10-25T23:00:00Z', '2026-10-26', 0];
    }

    #[DataProvider('daylightSavingTime')]
    public function testDaylightSavingTime(string $now, string $today, int $daysUntil): void
    {
        $this->deviceDue('A', '2026-10-26');
        $this->clock->set($now);

        $json = $this->get('inspection-due?days=365');

        self::assertSame($today, $json['today']);
        self::assertSame([['A', $daysUntil]], self::rows($json['due_soon'], 'days_until_due'));
    }

    public function testFilterByTypeAndLocation(): void
    {
        $radio = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $g2 = $this->createSubLocation($this->tenantId, $this->hlf, ['name' => 'G2']);
        $this->createDevice($this->tenantId, $this->typeId, $this->hlf, ['identifier' => 'A', 'next_inspection_due' => '2026-06-01']);
        $this->createDevice($this->tenantId, $radio, $this->g1, ['identifier' => 'B', 'next_inspection_due' => '2026-06-02']);
        $this->createDevice($this->tenantId, $this->typeId, $g2, ['identifier' => 'C', 'next_inspection_due' => '2026-06-20']);
        $this->createDevice($this->tenantId, $this->typeId, $this->room, ['identifier' => 'D', 'next_inspection_due' => null]);

        self::assertSame([['A', 'B'], ['C'], ['D']], $this->sections(''));
        self::assertSame([['B'], [], []], $this->sections('device_type_id=' . $radio));
        self::assertSame([['A'], [], []], $this->sections('location_id=' . $this->hlf));
        self::assertSame([['A', 'B'], ['C'], []], $this->sections('location_id=' . $this->hlf . '&include_sublocations=1'));
        self::assertSame([['A'], ['C'], []], $this->sections('location_id=' . $this->hlf . '&include_sublocations=1&device_type_id=' . $this->typeId));
        self::assertSame([[], [], ['D']], $this->sections('location_id=' . $this->room));
        self::assertSame([[], [], []], $this->sections('location_id=' . $this->otherLocation));
        self::assertSame([[], [], []], $this->sections('device_type_id=' . $this->otherTypeId));
        self::assertSame([[], [], []], $this->sections('device_type_id=999999999'));

        self::assertError(400, 'invalid_request', $this->api('GET', 'inspection-due?include_sublocations=1', cookie: $this->user));
        self::assertError(400, 'invalid_request', $this->api('GET', 'inspection-due?location_id=abc', cookie: $this->user));
        self::assertError(400, 'invalid_request', $this->api('GET', 'inspection-due?device_type_id=0', cookie: $this->user));
    }

    public function testLimitPerSectionWithTotal(): void
    {
        foreach (['A', 'B', 'C'] as $i => $identifier) {
            $this->deviceDue($identifier, '2026-06-0' . ($i + 1));
            $this->deviceDue('S' . $identifier, '2026-06-2' . ($i + 1));
            $this->deviceDue('N' . $identifier, null);
        }

        $json = $this->get('inspection-due?limit=2&include_without_date=1');

        self::assertSame([['A', 'B'], 3], [self::identifiers($json['overdue']), $json['overdue']['total']]);
        self::assertSame([['SA', 'SB'], 3], [self::identifiers($json['due_soon']), $json['due_soon']['total']]);
        self::assertSame([['NA', 'NB'], 3], [self::identifiers($json['without_date']), $json['without_date']['total']]);
        self::assertCount(3, $this->get('inspection-due?limit=500')['overdue']['devices']);
        foreach (['0', '501', 'x'] as $limit) {
            self::assertError(400, 'invalid_request', $this->api('GET', 'inspection-due?limit=' . $limit, cookie: $this->user));
        }
    }

    public function testOnlyDevicesOfOwnTenant(): void
    {
        $this->deviceDue('Eigen', '2026-06-01');
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'Fremd-1', 'next_inspection_due' => '2026-06-01']);
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'Fremd-2', 'next_inspection_due' => '2026-06-20']);
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'Fremd-3', 'next_inspection_due' => null]);

        self::assertSame([['Eigen'], [], []], $this->sections('', $this->admin));

        $this->account('tuttlingen', 'nachbar@example.org');
        $neighbour = $this->loginCookie('tuttlingen', 'nachbar@example.org');
        self::assertSame([['Fremd-1'], ['Fremd-2'], ['Fremd-3']], $this->sections('', $neighbour, 'tuttlingen'));
    }

    /**
     * @return list<list<string>> Kennungen je Abschnitt (überfällig, bald fällig, ohne Datum)
     */
    private function sections(string $query, ?string $cookie = null, string $slug = 'immendingen'): array
    {
        $response = $this->api('GET', 'inspection-due?include_without_date=1&' . $query, cookie: $cookie ?? $this->user, slug: $slug);
        self::assertSame(200, $response->statusCode(), $response->body());
        $json = self::json($response);

        return [self::identifiers($json['overdue']), self::identifiers($json['due_soon']), self::identifiers($json['without_date'])];
    }

    /**
     * @param array<string, mixed> $section
     *
     * @return list<string>
     */
    private static function identifiers(array $section): array
    {
        return array_column($section['devices'], 'identifier');
    }

    /**
     * @param array<string, mixed> $section
     *
     * @return list<array{mixed, mixed}> Kennung und Tage
     */
    private static function rows(array $section, string $days): array
    {
        return array_map(static fn (array $d): array => [$d['identifier'], $d[$days]], $section['devices']);
    }
}
