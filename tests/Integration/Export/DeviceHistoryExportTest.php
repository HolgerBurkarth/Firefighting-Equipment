<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Export;

use FirefightingEquipment\Api\V1\Devices\DeviceCsv;
use FirefightingEquipment\Api\V1\Devices\DeviceHistoryExportHandler;
use FirefightingEquipment\Device\DeviceEventRepository;
use FirefightingEquipment\Device\DeviceHistoryEntry;
use FirefightingEquipment\Device\DeviceHistoryFilter;
use FirefightingEquipment\Http\Access;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\Router;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * GET device-events/export (angemeldet): Historie der Stadt als CSV, Zeitraum in Kalendertagen
 * deutscher Zeit (Standard 90 Tage, höchstens 366), Filter Gerät, aktueller Gerätetyp und Von- oder
 * Nach-Standort, neueste oder älteste zuerst, Obergrenze der Einträge.
 */
#[CoversClass(DeviceHistoryExportHandler::class)]
#[CoversClass(DeviceCsv::class)]
#[CoversClass(DeviceEventRepository::class)]
#[CoversClass(DeviceHistoryEntry::class)]
#[CoversClass(DeviceHistoryFilter::class)]
final class DeviceHistoryExportTest extends ExportApiTestCase
{
    private const string HEADER = "\xEF\xBB\xBFAktionszeit;Eingang beim Server;Geräte-Nr.;Kennung;Gerätetyp;Ereignis;Wer;"
        . "Standort von;Standort nach;Status von;Status nach;Prüfdatum alt;Prüfdatum neu;Feld;Alter Wert;Neuer Wert;"
        . "Kommentar;Übernommen;Grund (nicht übernommen);Buchungs-Kennung\r\n";

    private int $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01']);
    }

    public function testHeadersAndDefaultPeriodInFilename(): void
    {
        [$response, $body] = $this->download('device-events/export');

        self::assertSame(200, $response->statusCode(), $body);
        self::assertSame('text/csv; charset=utf-8', $response->headers()['Content-Type']);
        self::assertSame('attachment; filename="historie-immendingen-2026-03-18-bis-2026-06-15.csv"', $response->headers()['Content-Disposition']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertSame(self::HEADER, $body);
    }

    public function testAllColumnsInGermanFormat(): void
    {
        $this->event('2026-06-14 22:30:00', ['event_type' => 'created', 'to_location_id' => $this->g1, 'to_status' => 'ready', 'comment' => 'Neu beschafft']);
        $this->event('2026-06-15 08:05:00', [
            'event_type' => 'state_changed', 'from_location_id' => $this->g1, 'to_location_id' => $this->room,
            'from_status' => 'ready', 'to_status' => 'defective', 'comment' => 'Riss; "Ventil"',
        ], $this->userId);
        $this->event('2026-06-15 08:06:00', ['event_type' => 'due_changed', 'from_due' => null, 'to_due' => '2027-03-01']);
        $this->event('2026-06-15 08:07:00', ['event_type' => 'field_changed', 'field' => 'procured_on', 'old_value' => '2024-05-01', 'new_value' => '2024-05-02']);
        $this->event('2026-06-15 08:08:00', ['event_type' => 'field_changed', 'field' => 'note', 'old_value' => 'alt', 'new_value' => "=Größe\nM"]);

        [, $body] = $this->download('device-events/export?order=oldest');

        $id = (string) $this->device;
        self::assertSame(
            self::HEADER
            . "15.06.2026 00:30;15.06.2026 00:30;$id;MA-01;Atemschutzmaske;Angelegt;Kommandant;;HLF 20 / G1;;einsatzbereit;;;;;;Neu beschafft;Ja;;\r\n"
            . "15.06.2026 10:05;15.06.2026 10:05;$id;MA-01;Atemschutzmaske;Umgebucht und Status geändert;Gerätewart;HLF 20 / G1;Lager;einsatzbereit;defekt;;;;;;\"Riss; \"\"Ventil\"\"\";Ja;;\r\n"
            . "15.06.2026 10:06;15.06.2026 10:06;$id;MA-01;Atemschutzmaske;Prüfdatum geändert;Kommandant;;;;;;01.03.2027;;;;;Ja;;\r\n"
            . "15.06.2026 10:07;15.06.2026 10:07;$id;MA-01;Atemschutzmaske;Stammdaten geändert;Kommandant;;;;;;;Beschaffungsdatum;01.05.2024;02.05.2024;;Ja;;\r\n"
            . "15.06.2026 10:08;15.06.2026 10:08;$id;MA-01;Atemschutzmaske;Stammdaten geändert;Kommandant;;;;;;;Notiz;alt;\"'=Größe\nM\";;Ja;;\r\n",
            $body,
        );
    }

    public function testOfflineColumns(): void
    {
        $moved = $this->event('2026-06-15 08:05:00', [
            'event_type' => 'state_changed', 'from_location_id' => $this->g1, 'to_location_id' => $this->room, 'occurred_at' => '2026-06-14 06:00:00',
        ], $this->userId);
        $this->insert('fe_device_bookings', [
            'tenant_id' => $this->tenantId, 'client_ref' => '3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f', 'user_id' => $this->userId,
            'device_id' => $this->device, 'request_hash' => str_repeat("\0", 32), 'outcome' => 'applied', 'event_id' => $moved,
        ]);
        $this->event('2026-06-15 08:06:00', [
            'event_type' => 'state_changed', 'to_status' => 'defective', 'occurred_at' => '2026-06-13 06:00:00',
            'applied' => 0, 'not_applied_reason' => 'retired',
        ], $this->userId);

        [, $body] = $this->download('device-events/export?order=oldest');

        $id = (string) $this->device;
        self::assertSame(
            self::HEADER
            . "14.06.2026 08:00;15.06.2026 10:05;$id;MA-01;Atemschutzmaske;Umgebucht;Gerätewart;HLF 20 / G1;Lager;;;;;;;;;Ja;;3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f\r\n"
            . "13.06.2026 08:00;15.06.2026 10:06;$id;MA-01;Atemschutzmaske;Statusänderung nicht übernommen;Gerätewart;;;;defekt;;;;;;;Nein;Gerät ausgesondert;\r\n",
            $body,
        );
    }

    public function testNewestFirstByDefaultAndIdWithinTheSameSecond(): void
    {
        // Gleiche Sekunde: die höhere ID ist neuer. Der letzte Eintrag hat den frühesten Zeitpunkt.
        $this->event('2026-06-15 08:00:00', ['event_type' => 'created', 'to_location_id' => $this->g1, 'to_status' => 'ready']);
        $this->event('2026-06-15 08:00:00', ['event_type' => 'due_changed', 'to_due' => '2027-03-01']);
        $this->event('2026-06-14 08:00:00', ['event_type' => 'due_changed', 'to_due' => '2027-04-01']);

        self::assertSame(['Prüfdatum geändert', 'Angelegt', 'Prüfdatum geändert'], self::column($this->rows('device-events/export'), 5));
        self::assertSame(['01.03.2027', '', '01.04.2027'], self::column($this->rows('device-events/export'), 12));
        self::assertSame(['01.04.2027', '', '01.03.2027'], self::column($this->rows('device-events/export?order=oldest'), 12));
    }

    public function testPeriodUsesCalendarDaysInGermanTime(): void
    {
        foreach (['2026-06-09 21:59:59', '2026-06-09 22:00:00', '2026-06-10 21:59:59', '2026-06-10 22:00:00'] as $i => $at) {
            $this->event($at, ['event_type' => 'due_changed', 'to_due' => sprintf('2027-01-0%d', $i + 1)]);
        }

        $rows = $this->rows('device-events/export?from=2026-06-10&to=2026-06-10&order=oldest');

        self::assertSame(['10.06.2026 00:00', '10.06.2026 23:59'], self::column($rows, 0));
        self::assertSame(['02.01.2027', '03.01.2027'], self::column($rows, 12));
    }

    public function testDefaultPeriodIsNinetyDaysUntilToday(): void
    {
        // 18.03.2026 00:00 MEZ = 17.03. 23:00 UTC; heute endet am 15.06. 22:00 UTC (MESZ).
        foreach (['2026-03-17 22:59:59', '2026-03-17 23:00:00', '2026-06-15 21:59:59', '2026-06-15 22:00:00'] as $i => $at) {
            $this->event($at, ['event_type' => 'due_changed', 'to_due' => sprintf('2027-01-0%d', $i + 1)]);
        }

        self::assertSame(['18.03.2026 00:00', '15.06.2026 23:59'], self::column($this->rows('device-events/export?order=oldest'), 0));
    }

    public function testOnlyOneBoundGiven(): void
    {
        [$response] = $this->download('device-events/export?from=2026-06-01');
        self::assertSame('attachment; filename="historie-immendingen-2026-06-01-bis-2026-06-15.csv"', $response->headers()['Content-Disposition']);

        [$response] = $this->download('device-events/export?to=2026-05-31');
        self::assertSame('attachment; filename="historie-immendingen-2026-03-03-bis-2026-05-31.csv"', $response->headers()['Content-Disposition']);
    }

    public function testPeriodLimits(): void
    {
        [$response, $body] = $this->download('device-events/export?from=2027-01-01&to=2028-01-01');
        self::assertSame(200, $response->statusCode(), $body);

        self::assertError(400, 'invalid_request', $this->get400('device-events/export?from=2027-01-01&to=2028-01-02'));
        self::assertError(400, 'invalid_request', $this->get400('device-events/export?from=2026-06-02&to=2026-06-01'));
        self::assertError(400, 'invalid_request', $this->get400('device-events/export?from=2026-02-30'));
        self::assertError(400, 'invalid_request', $this->get400('device-events/export?to=15.06.2026'));
        self::assertError(400, 'invalid_request', $this->get400('device-events/export?order=desc'));
        self::assertError(400, 'invalid_request', $this->get400('device-events/export?include_sublocations=1'));
        self::assertError(400, 'invalid_request', $this->get400('device-events/export?device_id=0'));
    }

    public function testFilterByDeviceAndCurrentDeviceType(): void
    {
        $radio = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $other = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'FU-01']);
        $this->event('2026-06-15 08:00:00', ['event_type' => 'created', 'to_location_id' => $this->g1, 'to_status' => 'ready']);
        $this->event('2026-06-15 08:01:00', ['event_type' => 'created', 'to_location_id' => $this->g1, 'to_status' => 'ready'], device: $other);
        $this->event('2026-06-15 08:02:00', ['event_type' => 'field_changed', 'field' => 'device_type', 'old_value' => 'Atemschutzmaske', 'new_value' => 'Funkgerät'], device: $other);
        $this->pdo()->prepare('UPDATE fe_devices SET device_type_id = ? WHERE id = ?')->execute([$radio, $other]);

        self::assertSame(['MA-01'], self::column($this->rows('device-events/export?device_id=' . $this->device), 3));
        self::assertSame(['FU-01', 'FU-01'], self::column($this->rows('device-events/export?device_type_id=' . $radio), 3));
        self::assertSame(['Funkgerät', 'Funkgerät'], self::column($this->rows('device-events/export?device_type_id=' . $radio), 4));
        self::assertSame(['MA-01'], self::column($this->rows('device-events/export?device_type_id=' . $this->typeId), 3));
    }

    public function testFilterByFromOrToLocation(): void
    {
        $created = $this->event('2026-06-15 08:00:00', ['event_type' => 'created', 'to_location_id' => $this->g1, 'to_status' => 'ready']);
        $toRoom = $this->event('2026-06-15 08:01:00', ['event_type' => 'state_changed', 'from_location_id' => $this->g1, 'to_location_id' => $this->room]);
        $statusOnly = $this->event('2026-06-15 08:02:00', ['event_type' => 'state_changed', 'from_status' => 'ready', 'to_status' => 'defective']);
        $due = $this->event('2026-06-15 08:03:00', ['event_type' => 'due_changed', 'to_due' => '2027-03-01']);
        $toHlf = $this->event('2026-06-15 08:04:00', ['event_type' => 'state_changed', 'from_location_id' => $this->room, 'to_location_id' => $this->hlf]);
        self::assertSame(5, count([$created, $toRoom, $statusOnly, $due, $toHlf]));

        self::assertSame(['Angelegt', 'Umgebucht'], self::column($this->rows('device-events/export?order=oldest&location_id=' . $this->g1), 5));
        self::assertSame([['HLF 20 / G1', 'Lager'], ['Lager', 'HLF 20']], array_map(
            static fn (array $row): array => [$row[7], $row[8]],
            $this->rows('device-events/export?order=oldest&location_id=' . $this->room),
        ));
        self::assertSame(['10:04'], array_map(static fn (string $at): string => substr($at, -5), self::column($this->rows('device-events/export?location_id=' . $this->hlf), 0)));
        self::assertSame(
            ['10:00', '10:01', '10:04'],
            array_map(static fn (string $at): string => substr($at, -5), self::column($this->rows('device-events/export?order=oldest&location_id=' . $this->hlf . '&include_sublocations=1'), 0)),
        );
    }

    public function testRetiredDevicesAndReusedIdentifiersAreDistinguishable(): void
    {
        $this->pdo()->prepare("UPDATE fe_devices SET status = 'retired' WHERE id = ?")->execute([$this->device]);
        $this->event('2026-06-15 08:00:00', ['event_type' => 'state_changed', 'from_status' => 'ready', 'to_status' => 'retired']);
        $new = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01']);
        $this->event('2026-06-15 08:01:00', ['event_type' => 'created', 'to_location_id' => $this->g1, 'to_status' => 'ready'], device: $new);

        $rows = $this->rows('device-events/export?order=oldest');

        self::assertSame([[(string) $this->device, 'MA-01', 'Ausgesondert'], [(string) $new, 'MA-01', 'Angelegt']], array_map(
            static fn (array $row): array => [$row[2], $row[3], $row[5]],
            $rows,
        ));
    }

    public function testOnlyEventsOfOwnTenant(): void
    {
        $this->event('2026-06-15 08:00:00', ['event_type' => 'created', 'to_location_id' => $this->g1, 'to_status' => 'ready']);
        $otherUser = $this->account('tuttlingen', 'user@example.org');
        $otherDevice = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'MA-99']);
        $this->insert('fe_device_events', [
            'tenant_id' => $this->otherTenantId, 'device_id' => $otherDevice, 'user_id' => $otherUser, 'event_type' => 'created',
            'to_location_id' => $this->otherLocation, 'to_status' => 'ready', 'created_at' => '2026-06-15 08:00:00',
        ]);

        self::assertSame(['MA-01'], self::column($this->rows('device-events/export'), 3));
        self::assertSame([], $this->rows('device-events/export?device_id=' . $otherDevice));
        self::assertSame([], $this->rows('device-events/export?location_id=' . $this->otherLocation));

        $response = $this->sendAny('GET', self::url('tuttlingen', 'device-events/export'), cookie: $this->loginCookie('tuttlingen', 'user@example.org'));
        $rows = array_slice(self::parse(self::responseBody($response)), 1);
        self::assertSame(['MA-99'], self::column($rows, 3));
    }

    public function testRowLimitIsCheckedBeforeStreaming(): void
    {
        foreach (['08:00:00', '08:01:00', '08:02:00'] as $i => $time) {
            $this->event('2026-06-15 ' . $time, ['event_type' => 'due_changed', 'to_due' => sprintf('2027-01-0%d', $i + 1)]);
        }
        $app = $this->app(tenant: Router::forTenant()->add('GET', 'api/v1/device-events/export', Access::Authenticated, new DeviceHistoryExportHandler($this->clock, maxRows: 2)));

        [$response, $body] = $this->download('device-events/export', app: $app);
        self::assertSame(409, $response->statusCode(), $body);
        self::assertSame(
            ['error' => 'rule_violation', 'message' => 'Der Zeitraum enthält 3 Einträge, höchstens 2 sind möglich. Bitte den Zeitraum verkleinern oder filtern.', 'details' => ['events' => 3, 'max' => 2]],
            json_decode($body, true, flags: JSON_THROW_ON_ERROR),
        );

        [$response, $body] = $this->download('device-events/export?from=2026-06-15&to=2026-06-15&device_id=' . $this->device . '&order=oldest', app: $app);
        self::assertSame(409, $response->statusCode(), $body);

        [$response, $body] = $this->download('device-events/export?from=2026-06-16&to=2026-06-20', app: $app);
        self::assertSame(200, $response->statusCode(), $body);
    }

    public function testMaxRowsDefault(): void
    {
        self::assertSame(100_000, DeviceHistoryExportHandler::MAX_ROWS);
        self::assertSame(90, DeviceHistoryExportHandler::DEFAULT_DAYS);
        self::assertSame(366, DeviceHistoryExportHandler::MAX_DAYS);
    }

    /**
     * Eintrag der Historie (Standard: Gerät MA-01, vom Admin).
     *
     * @param array<string, scalar|null> $values
     */
    private function event(string $createdAt, array $values, ?int $userId = null, ?int $device = null): int
    {
        return $this->insert('fe_device_events', [
            'tenant_id' => $this->tenantId,
            'device_id' => $device ?? $this->device,
            'user_id' => $userId ?? $this->adminId,
            'created_at' => $createdAt,
            ...$values,
        ]);
    }

    private function get400(string $path): JsonResponse
    {
        return $this->api('GET', $path, cookie: $this->user);
    }
}
