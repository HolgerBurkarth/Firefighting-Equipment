<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Export;

use FirefightingEquipment\Api\V1\Devices\DeviceCsv;
use FirefightingEquipment\Api\V1\Devices\DeviceExportHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceListHandler;
use FirefightingEquipment\Csv\CsvFormat;
use FirefightingEquipment\Database\UnbufferedQuery;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Http\CsvResponse;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * GET devices/export (angemeldet): Geräteliste als CSV (BOM, Semikolon, CRLF, deutsche Überschriften
 * und Werte, Formelschutz), Filter und Sortierung wie GET devices, ohne Seitenbegrenzung.
 */
#[CoversClass(DeviceExportHandler::class)]
#[CoversClass(DeviceListHandler::class)]
#[CoversClass(DeviceCsv::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(UnbufferedQuery::class)]
#[CoversClass(CsvResponse::class)]
final class DeviceExportTest extends ExportApiTestCase
{
    public function testHeadersFilenameAndHeaderLine(): void
    {
        [$response, $body] = $this->download('devices/export');

        self::assertSame(200, $response->statusCode(), $body);
        self::assertInstanceOf(CsvResponse::class, $response);
        self::assertSame('text/csv; charset=utf-8', $response->headers()['Content-Type']);
        self::assertSame('attachment; filename="geraete-immendingen-2026-06-15.csv"', $response->headers()['Content-Disposition']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertSame(
            "\xEF\xBB\xBFKennung;Gerätetyp;Standort;Status;Nächste Prüfung;Seriennummer;Hersteller;Modell;Beschaffungsdatum;Notiz\r\n",
            $body,
        );
    }

    public function testFilenameUsesGermanDate(): void
    {
        $this->clock->set('2026-06-15T22:30:00Z');

        [$response] = $this->download('devices/export');

        self::assertSame('attachment; filename="geraete-immendingen-2026-06-16.csv"', $response->headers()['Content-Disposition']);
    }

    public function testAllColumnsWithQuotingLineBreaksAndUmlauts(): void
    {
        $this->createViaApi('MA-01', [
            'status' => 'maintenance',
            'serial_number' => 'S;1',
            'manufacturer' => 'Dräger',
            'model' => 'FPS "7000"',
            'procured_on' => '2024-05-01',
            'next_inspection_due' => '2027-03-01',
            'note' => "Ventil prüfen\nGröße M",
        ]);
        $this->createViaApi('Wärmebild 1', ['location_id' => $this->room]);

        [, $body] = $this->download('devices/export');

        self::assertSame(
            "\xEF\xBB\xBFKennung;Gerätetyp;Standort;Status;Nächste Prüfung;Seriennummer;Hersteller;Modell;Beschaffungsdatum;Notiz\r\n"
            . "MA-01;Atemschutzmaske;HLF 20 / G1;in Wartung;01.03.2027;\"S;1\";Dräger;\"FPS \"\"7000\"\"\";01.05.2024;\"Ventil prüfen\nGröße M\"\r\n"
            . "Wärmebild 1;Atemschutzmaske;Lager;einsatzbereit;;;;;;\r\n",
            $body,
        );
        self::assertSame([
            ['MA-01', 'Atemschutzmaske', 'HLF 20 / G1', 'in Wartung', '01.03.2027', 'S;1', 'Dräger', 'FPS "7000"', '01.05.2024', "Ventil prüfen\nGröße M"],
            ['Wärmebild 1', 'Atemschutzmaske', 'Lager', 'einsatzbereit', '', '', '', '', '', ''],
        ], $this->rows('devices/export'));
    }

    public function testFormulaGuard(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => '-12']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, [
            'identifier' => 'MA-01',
            'serial_number' => '+49 7462',
            'manufacturer' => '=HYPERLINK("http://example.org")',
            'model' => '@SUMME(A1)',
            'note' => "'=1",
        ]);

        [, $body] = $this->download('devices/export');

        self::assertStringContainsString("\r\n'-12;", $body);
        self::assertStringContainsString(
            "\r\nMA-01;Atemschutzmaske;HLF 20 / G1;einsatzbereit;;'+49 7462;\"'=HYPERLINK(\"\"http://example.org\"\")\";'@SUMME(A1);;''=1\r\n",
            $body,
        );
        $rows = array_slice(self::parse($body), 1);
        self::assertSame(['-12', 'MA-01'], array_map(CsvFormat::removeFormulaGuard(...), self::column($rows, 0)));
        self::assertSame("'=1", CsvFormat::removeFormulaGuard($rows[1][9]));
    }

    public function testNaturalSortAndRetiredOnlyOnRequest(): void
    {
        foreach (['MA-10', 'MA-2', 'MA-1', 'ma-3'] as $identifier) {
            $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => $identifier]);
        }
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-4', 'status' => 'retired']);

        self::assertSame(['MA-1', 'MA-2', 'ma-3', 'MA-10'], self::column($this->rows('devices/export'), 0));
        self::assertSame(['MA-1', 'MA-2', 'ma-3', 'MA-4', 'MA-10'], self::column($this->rows('devices/export?include_retired=1'), 0));
        self::assertSame([['MA-4', 'ausgesondert']], array_map(
            static fn (array $row): array => [$row[0], $row[3]],
            $this->rows('devices/export?status=retired'),
        ));
    }

    public function testFiltersLikeDeviceList(): void
    {
        $radio = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01', 'manufacturer' => 'Dräger']);
        $this->createDevice($this->tenantId, $this->typeId, $this->hlf, ['identifier' => 'MA-02', 'status' => 'defective']);
        $this->createDevice($this->tenantId, $radio, $this->room, ['identifier' => 'FU-01']);

        self::assertSame(['FU-01'], self::column($this->rows('devices/export?device_type_id=' . $radio), 0));
        self::assertSame(['MA-02'], self::column($this->rows('devices/export?location_id=' . $this->hlf), 0));
        self::assertSame(['MA-01', 'MA-02'], self::column($this->rows('devices/export?location_id=' . $this->hlf . '&include_sublocations=1'), 0));
        self::assertSame(['MA-02'], self::column($this->rows('devices/export?status=defective'), 0));
        self::assertSame(['MA-01'], self::column($this->rows('devices/export?q=dräger'), 0));
        self::assertSame([], $this->rows('devices/export?location_id=' . $this->otherLocation));
    }

    public function testIgnoresPagingButUsesSortLikeDeviceList(): void
    {
        foreach (['MA-2', 'MA-1', 'MA-3'] as $identifier) {
            $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => $identifier, 'next_inspection_due' => match ($identifier) {
                'MA-2' => '2026-07-01',
                'MA-3' => '2026-08-01',
                default => null,
            }]);
        }

        self::assertSame(['MA-1', 'MA-2', 'MA-3'], self::column($this->rows('devices/export?limit=1&offset=1'), 0));
        self::assertSame(['MA-3', 'MA-2', 'MA-1'], self::column($this->rows('devices/export?dir=desc'), 0));
        self::assertSame(['MA-2', 'MA-3', 'MA-1'], self::column($this->rows('devices/export?sort=next_inspection_due&limit=1'), 0));
        self::assertSame(['MA-3', 'MA-2', 'MA-1'], self::column($this->rows('devices/export?sort=next_inspection_due&dir=desc'), 0));
    }

    public function testOnlyDevicesOfOwnTenant(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01']);
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'MA-02']);
        $this->account('tuttlingen', 'user@example.org');

        self::assertSame(['MA-01'], self::column($this->rows('devices/export', $this->admin), 0));

        $response = $this->sendAny('GET', self::url('tuttlingen', 'devices/export'), cookie: $this->loginCookie('tuttlingen', 'user@example.org'));
        $rows = array_slice(self::parse(self::responseBody($response)), 1);
        self::assertSame(['MA-02'], self::column($rows, 0));
        self::assertSame('attachment; filename="geraete-tuttlingen-2026-06-15.csv"', $response->headers()['Content-Disposition']);
    }

    public function testInvalidParametersAreJsonErrors(): void
    {
        self::assertError(400, 'invalid_request', $this->api('GET', 'devices/export?include_sublocations=1', cookie: $this->user));
        self::assertError(400, 'invalid_request', $this->api('GET', 'devices/export?status=kaputt', cookie: $this->user));
        self::assertError(400, 'invalid_request', $this->api('GET', 'devices/export?location_id=abc', cookie: $this->user));
        self::assertError(400, 'invalid_request', $this->api('GET', 'devices/export?sort=name', cookie: $this->user));
        self::assertError(400, 'invalid_request', $this->api('GET', 'devices/export?dir=up', cookie: $this->user));
        self::assertError(405, 'method_not_allowed', $this->api('POST', 'devices/export', []));
    }

    public function testManyRowsAreStreamedCompletely(): void
    {
        $count = 2 * CsvResponse::FLUSH_ROWS + 3;
        $values = [];
        $params = [];
        for ($i = 1; $i <= $count; ++$i) {
            $values[] = "(?, ?, ?, ?, 'ready')";
            array_push($params, $this->tenantId, sprintf('MA-%d', $i), $this->typeId, $this->g1);
        }
        $this->pdo()->prepare('INSERT INTO fe_devices (tenant_id, identifier, device_type_id, location_id, status) VALUES ' . implode(', ', $values))
            ->execute($params);

        $identifiers = self::column($this->rows('devices/export'), 0);

        self::assertCount($count, $identifiers);
        self::assertSame(['MA-1', 'MA-2', 'MA-3'], array_slice($identifiers, 0, 3));
        self::assertSame(sprintf('MA-%d', $count), $identifiers[$count - 1]);
    }

    public function testConnectionIsBufferedAgainAfterwards(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-02']);

        $this->rows('devices/export');
        self::assertTrue((bool) $this->pdo()->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY));

        // Nie gelesener Download: Verwerfen schließt die ungepufferte Abfrage.
        $response = $this->sendAny('GET', self::url('immendingen', 'devices/export'), cookie: $this->user);
        self::assertSame(200, $response->statusCode());
        unset($response);
        self::assertTrue((bool) $this->pdo()->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY));
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_devices')->fetchColumn());
    }
}
