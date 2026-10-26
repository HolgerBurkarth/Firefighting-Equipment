<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Inspection;

use FirefightingEquipment\Api\V1\Devices\DeviceSummaryHandler;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceSummary;
use FirefightingEquipment\Device\InspectionWindow;
use FirefightingEquipment\Time\LocalDate;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * GET device-summary (angemeldet): Anzahl je Status und Prüffristen ohne ausgesonderte Geräte,
 * Grenzen von 30 Tagen, deutsche Zeit, Mandantentrennung. Rechte: TenantReadRoutesAccessTest.
 */
#[CoversClass(DeviceSummaryHandler::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceSummary::class)]
#[CoversClass(InspectionWindow::class)]
#[CoversClass(LocalDate::class)]
final class DeviceSummaryTest extends InspectionApiTestCase
{
    public function testCountsWithoutRetired(): void
    {
        $this->deviceDue('Gestern', '2026-06-14');
        $this->deviceDue('Heute', '2026-06-15', ['status' => 'maintenance']);
        $this->deviceDue('In 30 Tagen', '2026-07-15', ['status' => 'defective']);
        $this->deviceDue('In 31 Tagen', '2026-07-16');
        $this->deviceDue('Ohne', null, ['status' => 'defective']);
        $this->deviceDue('Ohne 2', null);
        $this->deviceDue('Weg-1', '2026-06-01', ['status' => 'retired']);
        $this->deviceDue('Weg-2', '2026-06-20', ['status' => 'retired']);
        $this->deviceDue('Weg-3', null, ['status' => 'retired']);
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'Fremd', 'next_inspection_due' => '2026-06-01']);

        $json = $this->get('device-summary');

        self::assertSame([
            'today' => self::TODAY,
            'devices' => [
                'total' => 6,
                'by_status' => [
                    ['status' => 'ready', 'status_label' => 'einsatzbereit', 'count' => 3],
                    ['status' => 'maintenance', 'status_label' => 'in Wartung', 'count' => 1],
                    ['status' => 'defective', 'status_label' => 'defekt', 'count' => 2],
                ],
            ],
            'inspection' => ['overdue' => 1, 'due_soon' => 2, 'due_soon_days' => 30, 'without_date' => 2],
        ], $json);
        self::assertSame($json, $this->get('device-summary', $this->admin));
    }

    public function testEmptyTenantHasZeros(): void
    {
        $this->deviceDue('Weg', '2026-06-01', ['status' => 'retired']);

        self::assertSame([
            'today' => self::TODAY,
            'devices' => [
                'total' => 0,
                'by_status' => [
                    ['status' => 'ready', 'status_label' => 'einsatzbereit', 'count' => 0],
                    ['status' => 'maintenance', 'status_label' => 'in Wartung', 'count' => 0],
                    ['status' => 'defective', 'status_label' => 'defekt', 'count' => 0],
                ],
            ],
            'inspection' => ['overdue' => 0, 'due_soon' => 0, 'due_soon_days' => 30, 'without_date' => 0],
        ], $this->get('device-summary'));
    }

    public function testMidnightGermanTimeNotUtc(): void
    {
        $this->deviceDue('A', '2026-06-15');
        $this->deviceDue('B', '2026-07-16');

        $this->clock->set('2026-06-15T21:59:59Z');
        $json = $this->get('device-summary');
        self::assertSame([self::TODAY, 0, 1], [$json['today'], $json['inspection']['overdue'], $json['inspection']['due_soon']]);

        // 00:30 MESZ am 16.: A überfällig, B (heute + 30) bald fällig
        $this->clock->set('2026-06-15T22:30:00Z');
        $json = $this->get('device-summary');
        self::assertSame(['2026-06-16', 1, 1], [$json['today'], $json['inspection']['overdue'], $json['inspection']['due_soon']]);
    }

    public function testCountsMatchInspectionOverview(): void
    {
        foreach (['2026-05-01', '2026-06-14', '2026-06-15', '2026-07-01', '2026-07-15', '2026-07-16', null] as $i => $due) {
            $this->deviceDue('M' . $i, $due);
        }

        $summary = $this->get('device-summary')['inspection'];
        $overview = $this->get('inspection-due?include_without_date=1');

        self::assertSame(
            [$overview['overdue']['total'], $overview['due_soon']['total'], $overview['without_date']['total']],
            [$summary['overdue'], $summary['due_soon'], $summary['without_date']],
        );
        self::assertSame([2, 3, 1], [$summary['overdue'], $summary['due_soon'], $summary['without_date']]);
    }

    public function testOnlyDevicesOfOwnTenant(): void
    {
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'Fremd', 'next_inspection_due' => '2026-06-01']);
        $this->account('tuttlingen', 'nachbar@example.org');

        self::assertSame(0, $this->get('device-summary')['devices']['total']);

        $response = $this->api('GET', 'device-summary', cookie: $this->loginCookie('tuttlingen', 'nachbar@example.org'), slug: 'tuttlingen');
        self::assertSame(200, $response->statusCode(), $response->body());
        $json = self::json($response);
        self::assertSame([1, 1], [$json['devices']['total'], $json['inspection']['overdue']]);
    }
}
