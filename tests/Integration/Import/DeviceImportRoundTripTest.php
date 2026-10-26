<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Import;

use FirefightingEquipment\Api\V1\Devices\DeviceCsv;
use FirefightingEquipment\Csv\CsvFormat;
use FirefightingEquipment\Csv\CsvReader;
use FirefightingEquipment\Device\Import\DeviceImport;
use FirefightingEquipment\Device\Import\DeviceImportFile;
use FirefightingEquipment\Device\Import\DeviceImportPlanner;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Rundreise: Eine Datei aus GET devices/export ergibt beim Import nur „keine Änderung“ (auch mit
 * Sonderzeichen, Formelschutz, Unterstandorten und Namen mit „/“), und Übernehmen schreibt nichts.
 */
#[CoversClass(DeviceImport::class)]
#[CoversClass(DeviceImportPlanner::class)]
#[CoversClass(DeviceImportFile::class)]
#[CoversClass(CsvReader::class)]
#[CoversClass(DeviceCsv::class)]
final class DeviceImportRoundTripTest extends ImportApiTestCase
{
    public function testExportImportsWithoutChanges(): void
    {
        $this->createData(withSharpS: true);
        [, $export] = $this->download('devices/export');
        $before = $this->deviceSnapshot();

        $preview = $this->previewOk($export);

        self::assertSame(['rows' => 6, 'new' => 0, 'changed' => 0, 'unchanged' => 6, 'skipped' => 0, 'errors' => 0, 'warnings' => 0], $preview['summary']);
        self::assertSame(DeviceCsv::DEVICE_HEADER, $preview['columns']);
        self::assertIsString($preview['token']);

        $result = $this->apply($export, $preview['token'], 'Rundreise');
        self::assertSame(200, $result->statusCode(), $result->body());
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testExportWithRetiredDevicesSkipsThem(): void
    {
        $this->createData(withSharpS: true);
        // Ausgesondertes Gerät mit derselben Kennung wie ein aktives (nach dem Aussondern neu vergeben).
        $this->createDevice($this->tenantId, $this->typeId, $this->room, ['identifier' => 'MA-01', 'status' => 'retired']);
        [, $export] = $this->download('devices/export?include_retired=1');

        $preview = $this->previewOk($export);

        self::assertSame(['rows' => 7, 'new' => 0, 'changed' => 0, 'unchanged' => 6, 'skipped' => 1, 'errors' => 0, 'warnings' => 0], $preview['summary']);
        self::assertIsString($preview['token']);
    }

    public function testExportSavedByExcelAsWindows1252(): void
    {
        $this->createData(withSharpS: false);
        [, $export] = $this->download('devices/export');
        $ansi = mb_convert_encoding(substr($export, strlen(CsvFormat::BOM)), 'Windows-1252', 'UTF-8');

        $preview = $this->previewOk($ansi);

        self::assertSame('windows-1252', $preview['encoding']);
        self::assertSame(5, $preview['summary']['unchanged']);
        self::assertSame(0, $preview['summary']['errors']);
    }

    public function testChangedExportIsImportedAndExportedAgain(): void
    {
        $this->createData(withSharpS: false);
        [, $export] = $this->download('devices/export');
        $changed = str_replace(';Dräger;', ';MSA;', $export);

        $result = $this->import($changed);

        self::assertSame(1, $result['summary']['changed']);
        [, $again] = $this->download('devices/export');
        self::assertSame($changed, $again);
    }

    private function createData(bool $withSharpS): void
    {
        $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $cellar = $this->createLocation($this->tenantId, ['name' => 'Lager/Keller', 'kind' => 'room']);
        $shelf = $this->createSubLocation($this->tenantId, $cellar, ['name' => 'Regal 1', 'kind' => 'room']);

        $this->createViaApi('MA-01', [
            'status' => 'maintenance',
            'serial_number' => 'S;1',
            'manufacturer' => 'Dräger',
            'model' => 'FPS "7000"',
            'procured_on' => '2024-05-01',
            'next_inspection_due' => '2027-03-01',
            'note' => "Ventil prüfen;\nGröße M\n\"Achtung\"",
        ]);
        $this->createViaApi('MA-02', ['serial_number' => '-12', 'manufacturer' => '=SUMME(A1)', 'model' => "'Zitat", 'note' => '@Wartung']);
        $this->createViaApi('Wärmebild 1', ['location_id' => $this->room, 'status' => 'defective']);
        $this->createViaApi('007', ['location_id' => $shelf]);
        $this->createViaApi('HLF.20/1', ['location_id' => $cellar, 'next_inspection_due' => '2026-06-15']);
        if ($withSharpS) {
            $this->createViaApi('STRAẞE-1', ['note' => 'ẞ ist nicht in Windows-1252']);
        }
    }
}
