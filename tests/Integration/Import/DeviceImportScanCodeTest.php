<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Import;

use FirefightingEquipment\Api\V1\Devices\DeviceCsv;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\Import\DeviceImport;
use FirefightingEquipment\Device\Import\DeviceImportPlanner;
use FirefightingEquipment\Device\Import\ImportRow;
use FirefightingEquipment\Device\Import\ImportWarnings;
use FirefightingEquipment\Device\ScanCode;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Spalte „Scan-Code“ im CSV-Import und -Export: exakter Abgleich, leere Zelle leert, doppelt in der
 * Datei oder an ein anderes Gerät vergeben ist ein Fehler (kein Übertragen per Import), Warnungen zu
 * Excel-Umwandlungen, Rundreise.
 */
#[CoversClass(DeviceImport::class)]
#[CoversClass(DeviceImportPlanner::class)]
#[CoversClass(ImportRow::class)]
#[CoversClass(ImportWarnings::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceCsv::class)]
#[CoversClass(ScanCode::class)]
final class DeviceImportScanCodeTest extends ImportApiTestCase
{
    private const string COLUMNS = 'Kennung;Scan-Code;Gerätetyp;Standort';

    public function testNewDeviceWithScanCode(): void
    {
        $file = self::csv([
            ['MA-01', "0104006381333931\x1D10AB\t", 'Atemschutzmaske', 'Lager'],
            ['MA-02', '=SUMME(A1)', 'Atemschutzmaske', 'Lager'],
            ['MA-03', '', 'Atemschutzmaske', 'Lager'],
        ], self::COLUMNS);

        $preview = $this->previewOk($file);
        self::assertSame(
            [['identifier', null, 'MA-01'], ['scan_code', null, "0104006381333931\x1D10AB"], ['device_type', null, 'Atemschutzmaske']],
            array_slice(self::changes(self::row($preview, 2)), 0, 3),
        );
        self::assertNotContains('scan_code', array_column(self::row($preview, 4)['changes'], 'field'));

        $this->import($file, 'Erstbefüllung');

        self::assertSame("0104006381333931\x1D10AB", $this->deviceRow($this->deviceIdOf('MA-01'))['scan_code']);
        self::assertSame('=SUMME(A1)', $this->deviceRow($this->deviceIdOf('MA-02'))['scan_code'], 'Formelschutz entfernt');
        self::assertNull($this->deviceRow($this->deviceIdOf('MA-03'))['scan_code']);
        self::assertSame(['imported'], array_column($this->events($this->deviceIdOf('MA-01')), 'event_type'));
    }

    public function testChangesExistingScanCodes(): void
    {
        $set = $this->createViaApi('MA-01')['id'];
        $case = $this->createViaApi('MA-02', ['scan_code' => 'abc'])['id'];
        $cleared = $this->createViaApi('MA-03', ['scan_code' => 'X'])['id'];
        $same = $this->createViaApi('MA-04', ['scan_code' => "Y\x1DZ"])['id'];
        $file = self::csv([
            ['MA-01', 'neu'],
            ['MA-02', 'ABC'],
            ['MA-03', ''],
            ['MA-04', "Y\x1DZ\r"],
        ], 'Kennung;Scan-Code');

        $preview = $this->previewOk($file);
        self::assertSame([['scan_code', null, 'neu']], self::changes(self::row($preview, 2)));
        self::assertSame([['scan_code', 'abc', 'ABC']], self::changes(self::row($preview, 3)));
        self::assertSame([['scan_code', 'X', null]], self::changes(self::row($preview, 4)));
        self::assertSame('unchanged', self::row($preview, 5)['result']);

        $this->import($file, 'Etiketten');

        self::assertSame(['neu', 'ABC', null, "Y\x1DZ"], array_map(fn (int $id): ?string => $this->deviceRow($id)['scan_code'], [$set, $case, $cleared, $same]));
        $events = $this->events($case);
        self::assertSame(['field_changed', 'scan_code', 'abc', 'ABC', 'Etiketten'], [
            $events[1]['event_type'], $events[1]['field'], $events[1]['old_value'], $events[1]['new_value'], $events[1]['comment'],
        ]);
        self::assertCount(1, $this->events($same));
    }

    public function testMissingColumnLeavesScanCodeUnchanged(): void
    {
        $id = $this->createViaApi('MA-01', ['scan_code' => 'abc'])['id'];

        $this->import(self::csv([['MA-01', 'Lager']], 'Kennung;Standort'));

        self::assertSame('abc', $this->deviceRow($id)['scan_code']);
    }

    public function testDuplicateScanCodeInFileIsErrorInAllRows(): void
    {
        $preview = $this->previewOk(self::csv([
            ['MA-01', 'abc', 'Atemschutzmaske', 'Lager', ''],
            ['MA-02', 'ABC', 'Atemschutzmaske', 'Lager', ''],
            ['MA-03', "abc\t", 'Atemschutzmaske', 'Lager', ''],
            ['MA-04', 'abc', 'Atemschutzmaske', 'Lager', 'ausgesondert'],
        ], self::COLUMNS . ';Status'));

        self::assertNull($preview['token']);
        self::assertSame(['error', 'new', 'error', 'skipped'], array_column($preview['rows'], 'result'));
        self::assertSame(['Der Scan-Code „abc“ kommt in der Datei mehrfach vor (Zeilen 2, 4).'], self::row($preview, 2)['messages']);
        self::assertSame(self::row($preview, 2)['messages'], self::row($preview, 4)['messages']);
    }

    public function testScanCodeOfOtherDeviceIsErrorEvenIfReleasedInSameFile(): void
    {
        $this->createViaApi('MA-01', ['scan_code' => "A\x1D1"]);
        $this->createViaApi('MA-02', ['scan_code' => 'B']);
        $this->createViaApi('MA-03', ['scan_code' => 'C']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-09', 'scan_code' => 'R', 'status' => 'retired']);
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'MA-10', 'scan_code' => 'T']);

        $preview = $this->previewOk(self::csv([
            ['MA-05', 'C', 'Atemschutzmaske', 'Lager'],
            ['MA-01', 'B', '', ''],
            ['MA-02', "A\x1D1", '', ''],
            ['MA-06', 'R', 'Atemschutzmaske', 'Lager'],
            ['MA-07', 'T', 'Atemschutzmaske', 'Lager'],
        ], self::COLUMNS));

        self::assertNull($preview['token']);
        self::assertSame(['error', 'error', 'error', 'new', 'new'], array_column($preview['rows'], 'result'));
        self::assertSame([
            'Der Scan-Code „C“ ist bereits an das Gerät „MA-03“ vergeben. Per Import lassen sich Scan-Codes nicht übertragen; dazu den Scan-Code zuerst bei „MA-03“ entfernen.',
        ], self::row($preview, 2)['messages']);
        self::assertStringStartsWith('Der Scan-Code „B“ ist bereits an das Gerät „MA-02“ vergeben.', self::row($preview, 3)['messages'][0], 'Tausch');
        self::assertStringStartsWith('Der Scan-Code „A[GS]1“ ist bereits an das Gerät „MA-01“ vergeben.', self::row($preview, 4)['messages'][0], 'Tausch');
    }

    public function testWarningsForExcelConversions(): void
    {
        $this->createViaApi('MA-01', ['scan_code' => '04006381333931']);
        $this->createViaApi('MA-02', ['scan_code' => '0000815']);
        $this->createViaApi('MA-03', ['scan_code' => '0042']);

        $preview = $this->previewOk(self::csv([
            ['MA-01', '4006381333931', '', ''],
            ['MA-04', '815', 'Atemschutzmaske', 'Lager'],
            ['MA-05', '4,00638E+12', 'Atemschutzmaske', 'Lager'],
            ['MA-03', '0042', '', ''],
            ['MA-06', '000000815', 'Atemschutzmaske', 'Lager'],
        ], self::COLUMNS));

        self::assertIsString($preview['token'], 'Warnungen blockieren nicht');
        self::assertSame(3, $preview['summary']['warnings']);
        self::assertSame([
            'Der Scan-Code „4006381333931“ entspricht ohne führende Nullen dem vorhandenen Scan-Code „04006381333931“ (Gerät „MA-01“). Hat Excel führende Nullen entfernt? Dann die Datei mit der Spalte Scan-Code als Text öffnen.',
        ], self::row($preview, 2)['warnings']);
        self::assertStringContainsString('„0000815“ (Gerät „MA-02“)', self::row($preview, 3)['warnings'][0]);
        self::assertSame([
            'Der Scan-Code „4,00638E+12“ sieht wie eine Zahl in wissenschaftlicher Schreibweise aus. Hat Excel ihn umgewandelt (dabei gehen Ziffern verloren)? Dann die Datei mit der Spalte Scan-Code als Text öffnen.',
        ], self::row($preview, 4)['warnings']);
        self::assertSame([], self::row($preview, 5)['warnings'], 'unverändert');
        self::assertSame([], self::row($preview, 6)['warnings'], 'mehr Nullen als vorhanden: nicht von Excel');
    }

    public function testScanCodeTakenSincePreviewIsConflict(): void
    {
        $file = self::csv([['MA-05', 'abc', 'Atemschutzmaske', 'Lager']], self::COLUMNS);
        $token = $this->previewOk($file)['token'];
        $this->createViaApi('MA-01', ['scan_code' => 'abc']);
        $before = $this->deviceSnapshot();

        self::assertError(409, 'import_outdated', $this->apply($file, $token));
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testExportRoundTripWithScanCodes(): void
    {
        $this->createViaApi('MA-01', ['scan_code' => "0104006381333931\x1D10AB 7"]);
        $this->createViaApi('MA-02', ['scan_code' => '=1+2']);
        $this->createViaApi('MA-03', ['scan_code' => "'quote"]);
        $this->createViaApi('MA-04', ['scan_code' => '007;"x"']);
        $this->createViaApi('MA-05', ['scan_code' => "[)>\x1E06\x1D17V1\x1E\x04"]);
        $this->createViaApi('MA-06');
        [, $export] = $this->download('devices/export');
        $before = $this->deviceSnapshot();

        $preview = $this->previewOk($export);

        self::assertSame(DeviceCsv::DEVICE_HEADER, $preview['columns']);
        self::assertSame(['rows' => 6, 'new' => 0, 'changed' => 0, 'unchanged' => 6, 'skipped' => 0, 'errors' => 0, 'warnings' => 0], $preview['summary']);
        self::assertSame(200, $this->apply($export, $preview['token'])->statusCode());
        self::assertSame($before, $this->deviceSnapshot());
    }
}
