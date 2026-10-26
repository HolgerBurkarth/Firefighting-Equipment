<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Import;

use FirefightingEquipment\Api\V1\Devices\DeviceImportHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceImportJson;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\Import\DeviceImport;
use FirefightingEquipment\Device\Import\DeviceImportPlan;
use FirefightingEquipment\Device\Import\DeviceImportPlanner;
use FirefightingEquipment\Device\Import\ImportRowPlan;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * POST devices/import/preview: jede Zeile neu, Änderungen (alt → neu), keine Änderung, übersprungen
 * oder Fehler; Zusammenfassung, unbekannte Gerätetypen und Standorte, Warnungen. Speichert nie etwas.
 */
#[CoversClass(DeviceImportHandler::class)]
#[CoversClass(DeviceImportJson::class)]
#[CoversClass(DeviceImport::class)]
#[CoversClass(DeviceImportPlanner::class)]
#[CoversClass(DeviceImportPlan::class)]
#[CoversClass(ImportRowPlan::class)]
#[CoversClass(DeviceRepository::class)]
final class DeviceImportPreviewTest extends ImportApiTestCase
{
    public function testNewDeviceWithAllFieldsWritesNothing(): void
    {
        $before = $this->deviceSnapshot();

        $preview = $this->previewOk(self::csv([
            ['MA-01', 'Atemschutzmaske', 'HLF 20 / G1', 'in Wartung', '01.03.2027', '-12', 'Dräger', 'FPS 7000', '1.5.2024', "Ventil\nprüfen"],
        ]));

        self::assertSame($before, $this->deviceSnapshot());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $preview['token']);
        self::assertSame('utf-8', $preview['encoding']);
        self::assertSame(';', $preview['delimiter']);
        self::assertSame('Semikolon', $preview['delimiter_label']);
        self::assertSame(explode(';', self::HEADER), $preview['columns']);
        self::assertSame(['rows' => 1, 'new' => 1, 'changed' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0, 'warnings' => 0], $preview['summary']);
        self::assertSame(['device_types' => [], 'locations' => []], $preview['unknown']);
        self::assertSame([
            'line' => 2,
            'identifier' => 'MA-01',
            'result' => 'new',
            'result_label' => 'neu',
            'device' => null,
            'changes' => [
                ['field' => 'identifier', 'label' => 'Kennung', 'old' => null, 'new' => 'MA-01'],
                ['field' => 'device_type', 'label' => 'Gerätetyp', 'old' => null, 'new' => 'Atemschutzmaske'],
                ['field' => 'location', 'label' => 'Standort', 'old' => null, 'new' => 'HLF 20 / G1'],
                ['field' => 'status', 'label' => 'Status', 'old' => null, 'new' => 'in Wartung'],
                ['field' => 'next_inspection_due', 'label' => 'Nächste Prüfung', 'old' => null, 'new' => '01.03.2027'],
                ['field' => 'serial_number', 'label' => 'Seriennummer', 'old' => null, 'new' => '-12'],
                ['field' => 'manufacturer', 'label' => 'Hersteller', 'old' => null, 'new' => 'Dräger'],
                ['field' => 'model', 'label' => 'Modell', 'old' => null, 'new' => 'FPS 7000'],
                ['field' => 'procured_on', 'label' => 'Beschaffungsdatum', 'old' => null, 'new' => '01.05.2024'],
                ['field' => 'note', 'label' => 'Notiz', 'old' => null, 'new' => "Ventil\nprüfen"],
            ],
            'messages' => [],
            'warnings' => [],
        ], $preview['rows'][0]);
    }

    public function testNewDeviceNeedsOnlyTypeAndLocationAndIsReadyByDefault(): void
    {
        $preview = $this->previewOk(self::csv([['MA-01', 'Atemschutzmaske', 'Lager']], 'Kennung;Gerätetyp;Standort'));

        self::assertSame([
            ['identifier', null, 'MA-01'],
            ['device_type', null, 'Atemschutzmaske'],
            ['location', null, 'Lager'],
            ['status', null, 'einsatzbereit'],
        ], self::changes($preview['rows'][0]));
    }

    public function testChangesShowOldAndNewValues(): void
    {
        $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $device = $this->createViaApi('MA-01', [
            'serial_number' => 'S1',
            'manufacturer' => 'Dräger',
            'model' => 'FPS 7000',
            'procured_on' => '2024-05-01',
            'next_inspection_due' => '2027-03-01',
            'note' => 'alt',
        ]);

        $preview = $this->previewOk(self::csv([
            ['ma-01', 'Funkgerät', 'Lager', 'defekt', '2028-03-01', '', 'MSA', 'FPS 7000', '02.05.2024', "neu\nzweite Zeile"],
        ]));

        self::assertSame(['rows' => 1, 'new' => 0, 'changed' => 1, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0, 'warnings' => 0], $preview['summary']);
        $row = $preview['rows'][0];
        self::assertSame('changed', $row['result']);
        self::assertSame('Änderungen', $row['result_label']);
        self::assertSame('ma-01', $row['identifier']);
        self::assertSame(['id' => $device['id'], 'version' => 1], $row['device']);
        self::assertSame([
            ['identifier', 'MA-01', 'ma-01'],
            ['device_type', 'Atemschutzmaske', 'Funkgerät'],
            ['location', 'HLF 20 / G1', 'Lager'],
            ['status', 'einsatzbereit', 'defekt'],
            ['next_inspection_due', '01.03.2027', '01.03.2028'],
            ['serial_number', 'S1', null],
            ['manufacturer', 'Dräger', 'MSA'],
            ['procured_on', '01.05.2024', '02.05.2024'],
            ['note', 'alt', "neu\nzweite Zeile"],
        ], self::changes($row));
    }

    public function testUnchangedWhenValuesMatchLikeTheDatabase(): void
    {
        $this->createLocation($this->tenantId, ['name' => 'Gerätehaus Straße', 'kind' => 'room']);
        $this->createViaApi('MA-01', ['manufacturer' => 'Dräger', 'next_inspection_due' => '2027-03-01']);

        $preview = $this->previewOk(self::csv([
            ['MA-01', 'atemschutzmaske', 'hlf 20 / g1', 'Einsatzbereit', '1.3.2027', null, 'Dräger', null, null, null],
        ]));
        self::assertSame('unchanged', $preview['rows'][0]['result']);
        self::assertSame('keine Änderung', $preview['rows'][0]['result_label']);
        self::assertSame([], $preview['rows'][0]['changes']);
        self::assertIsString($preview['token']);

        $moved = $this->previewOk(self::csv([['MA-01', 'Gerätehaus Strasse']], 'Kennung;Standort'));
        self::assertSame([['location', 'HLF 20 / G1', 'Gerätehaus Straße']], self::changes($moved['rows'][0]), 'Straße = Strasse wie im UNIQUE-Index');
    }

    public function testMissingColumnKeepsFieldAndEmptyCellClears(): void
    {
        $this->createViaApi('MA-01', ['serial_number' => 'S1', 'note' => 'Notiz', 'next_inspection_due' => '2027-03-01']);

        $without = $this->previewOk(self::csv([['MA-01', 'defekt']], 'Kennung;Status'));
        self::assertSame([['status', 'einsatzbereit', 'defekt']], self::changes($without['rows'][0]));

        $empty = $this->previewOk(self::csv([['MA-01', '', '', '', '', '', '']], 'Kennung;Gerätetyp;Standort;Status;Seriennummer;Notiz;Nächste Prüfung'));
        self::assertSame([
            ['next_inspection_due', '01.03.2027', null],
            ['serial_number', 'S1', null],
            ['note', 'Notiz', null],
        ], self::changes($empty['rows'][0]), 'Pflichtfelder leer = unverändert, optionale werden geleert');
    }

    public function testRetiredStatusSkipsRowAndDoesNotCountAsDuplicate(): void
    {
        $this->createViaApi('MA-01');
        $this->createDevice($this->tenantId, $this->typeId, $this->room, ['identifier' => 'MA-01', 'status' => 'retired']);

        $preview = $this->previewOk(self::csv([
            ['MA-01', 'Atemschutzmaske', 'HLF 20 / G1', 'einsatzbereit'],
            ['MA-01', 'Atemschutzmaske', 'Lager', 'ausgesondert'],
            ['MA-77', 'Gibt es nicht', 'Nirgendwo', 'Ausgesondert'],
        ], 'Kennung;Gerätetyp;Standort;Status'));

        self::assertSame(['rows' => 3, 'new' => 0, 'changed' => 0, 'unchanged' => 1, 'skipped' => 2, 'errors' => 0, 'warnings' => 0], $preview['summary']);
        self::assertSame('skipped', self::row($preview, 3)['result']);
        self::assertSame('übersprungen', self::row($preview, 3)['result_label']);
        self::assertSame(
            ['Status „ausgesondert“: Die Zeile wird übersprungen. Aussondern und Wiederaufnehmen sind nur einzeln möglich.'],
            self::row($preview, 3)['messages'],
        );
        self::assertSame('skipped', self::row($preview, 4)['result']);
        self::assertSame(['device_types' => [], 'locations' => []], $preview['unknown']);
        self::assertIsString($preview['token']);
    }

    public function testUnknownTypesAndLocationsAreListedOnce(): void
    {
        $this->createViaApi('MA-01');

        $preview = $this->previewOk(self::csv([
            ['MA-02', 'Funkgerät', 'HLF 20 / G1'],
            ['MA-03', 'Funkgerät', 'LF 8'],
            ['MA-01', 'Atemschutzmaske', 'HLF 20 / G9'],
            ['MA-04', '112', 'HLF 20'],
        ], 'Kennung;Gerätetyp;Standort'));

        self::assertNull($preview['token']);
        self::assertSame(['rows' => 4, 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => 4, 'warnings' => 0], $preview['summary']);
        self::assertSame(['device_types' => ['Funkgerät', '112'], 'locations' => ['LF 8', 'HLF 20 / G9']], $preview['unknown']);
        self::assertSame(['Der Gerätetyp „Funkgerät“ ist nicht bekannt.'], self::row($preview, 2)['messages']);
        self::assertSame(['Der Gerätetyp „Funkgerät“ ist nicht bekannt.', 'Der Standort „LF 8“ ist nicht bekannt.'], self::row($preview, 3)['messages']);
        $existing = self::row($preview, 4);
        self::assertSame('error', $existing['result']);
        self::assertSame('Fehler', $existing['result_label']);
        self::assertSame(['Der Standort „HLF 20 / G9“ ist nicht bekannt.'], $existing['messages']);
        self::assertSame([], $existing['changes']);
        self::assertSame(1, $existing['device']['version']);
    }

    public function testAmbiguousPathIsError(): void
    {
        // Ein Standort der Ebene 1 darf „/“ im Namen haben und so wie ein Pfad aussehen.
        $this->createLocation($this->tenantId, ['name' => 'HLF 20 / G1', 'kind' => 'room']);

        $preview = $this->previewOk(self::csv([['MA-01', 'Atemschutzmaske', 'HLF 20 / G1']], 'Kennung;Gerätetyp;Standort'));

        self::assertSame(['Der Standort „HLF 20 / G1“ ist nicht eindeutig (mehrere Standorte haben diesen Pfad).'], $preview['rows'][0]['messages']);
        self::assertSame([], $preview['unknown']['locations']);
    }

    public function testLockedLocationAndInactiveTypeOnlyAsNewTarget(): void
    {
        $radio = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät', 'active' => 0]);
        $closed = $this->createLocation($this->tenantId, ['name' => 'Altbau', 'kind' => 'room', 'active' => 0]);
        $this->createDevice($this->tenantId, $radio, $closed, ['identifier' => 'FU-01']);
        $this->createViaApi('MA-01');

        $preview = $this->previewOk(self::csv([
            ['FU-01', 'Funkgerät', 'Altbau', 'defekt'],
            ['MA-01', 'Funkgerät', 'Altbau', 'defekt'],
            ['MA-02', 'Funkgerät', 'Altbau', ''],
        ], 'Kennung;Gerätetyp;Standort;Status'));

        self::assertSame('changed', self::row($preview, 2)['result'], 'bleibt an gesperrtem Standort und deaktiviertem Typ');
        self::assertSame([['status', 'einsatzbereit', 'defekt']], self::changes(self::row($preview, 2)));
        $expected = [
            'Der Gerätetyp „Funkgerät“ ist deaktiviert und kann keinem Gerät zugeordnet werden.',
            'Der Standort „Altbau“ ist gesperrt und kann keine Geräte aufnehmen.',
        ];
        self::assertSame($expected, self::row($preview, 3)['messages']);
        self::assertSame($expected, self::row($preview, 4)['messages']);
        self::assertSame([], $preview['unknown']['device_types']);
    }

    public function testNewDeviceNeedsTypeAndLocation(): void
    {
        $preview = $this->previewOk(self::csv([['MA-01', 'Atemschutzmaske', ''], ['MA-02', '', 'Lager']], 'Kennung;Gerätetyp;Standort'));
        self::assertSame(['Für ein neues Gerät fehlt der Standort.'], self::row($preview, 2)['messages']);
        self::assertSame(['Für ein neues Gerät fehlt der Gerätetyp.'], self::row($preview, 3)['messages']);

        $withoutColumns = $this->previewOk(self::csv([['MA-01', 'defekt']], 'Kennung;Status'));
        self::assertSame(['Für ein neues Gerät fehlt der Gerätetyp.', 'Für ein neues Gerät fehlt der Standort.'], $withoutColumns['rows'][0]['messages']);
    }

    public function testInvalidValuesAreRowErrors(): void
    {
        $preview = $this->previewOk(self::csv([
            ['MA-01', 'Atemschutzmaske', 'Lager', 'kaputt', '31.02.2027'],
            ['', 'Atemschutzmaske', 'Lager', '', ''],
        ], 'Kennung;Gerätetyp;Standort;Status;Nächste Prüfung'));

        self::assertSame([
            'Unbekannter Status „kaputt“ (möglich: einsatzbereit, in Wartung, defekt).',
            'Das Datum der nächsten Prüfung „31.02.2027“ ist kein gültiger Kalendertag.',
        ], self::row($preview, 2)['messages']);
        self::assertSame(['Die Kennung fehlt.'], self::row($preview, 3)['messages']);
        self::assertSame('', self::row($preview, 3)['identifier']);
    }

    public function testDuplicateIdentifiersIgnoreCaseButNotSharpS(): void
    {
        $preview = $this->previewOk(self::csv([
            ['MA-01', 'Atemschutzmaske', 'Lager'],
            ['ma-01', 'Atemschutzmaske', 'Lager'],
            ['STRAẞE-1', 'Atemschutzmaske', 'Lager'],
            ['straße-1', 'Atemschutzmaske', 'Lager'],
            ['STRASSE-1', 'Atemschutzmaske', 'Lager'],
        ], 'Kennung;Gerätetyp;Standort'));

        self::assertSame(['Die Kennung „MA-01“ kommt in der Datei mehrfach vor (Zeilen 2, 3).'], self::row($preview, 2)['messages']);
        self::assertSame(['Die Kennung „ma-01“ kommt in der Datei mehrfach vor (Zeilen 2, 3).'], self::row($preview, 3)['messages']);
        self::assertSame(['Die Kennung „STRAẞE-1“ kommt in der Datei mehrfach vor (Zeilen 4, 5).'], self::row($preview, 4)['messages']);
        self::assertSame('new', self::row($preview, 6)['result'], 'ß ≠ ss');
        self::assertSame(4, $preview['summary']['errors']);
    }

    public function testIdentifierIsMatchedLikeTheUniqueIndex(): void
    {
        foreach (['STRAẞE-1', 'ÉCLAIR-2', 'ÄÖÜ-3', 'ΣΩ-4'] as $identifier) {
            $this->createViaApi($identifier);
        }

        $preview = $this->previewOk(self::csv([['straße-1'], ['éclair-2'], ['äöü-3'], ['σω-4'], ['STRASSE-1'], ['ECLAIR-2']], 'Kennung'));

        self::assertSame([['identifier', 'STRAẞE-1', 'straße-1']], self::changes(self::row($preview, 2)));
        self::assertSame([['identifier', 'ÉCLAIR-2', 'éclair-2']], self::changes(self::row($preview, 3)));
        self::assertSame([['identifier', 'ÄÖÜ-3', 'äöü-3']], self::changes(self::row($preview, 4)));
        self::assertSame([['identifier', 'ΣΩ-4', 'σω-4']], self::changes(self::row($preview, 5)));
        self::assertNull(self::row($preview, 6)['device'], 'ß ≠ ss: neues Gerät');
        self::assertNull(self::row($preview, 7)['device'], 'é ≠ e: neues Gerät');
    }

    public function testWarningsForIdentifiersExcelProbablyChanged(): void
    {
        $this->createViaApi('007');
        $this->createViaApi('01.03.2026');

        $preview = $this->previewOk(self::csv([
            ['7', 'Atemschutzmaske', 'Lager'],
            ['1.3.26', 'Atemschutzmaske', 'Lager'],
            ['01.03.2026', 'Atemschutzmaske', 'HLF 20 / G1'],
            ['0007', 'Atemschutzmaske', 'Lager'],
            ['8', 'Atemschutzmaske', 'Lager'],
        ], 'Kennung;Gerätetyp;Standort'));

        self::assertSame(['rows' => 5, 'new' => 4, 'changed' => 0, 'unchanged' => 1, 'skipped' => 0, 'errors' => 0, 'warnings' => 3], $preview['summary']);
        self::assertIsString($preview['token'], 'Warnungen blockieren nicht');
        self::assertSame(
            ['Die neue Kennung „7“ entspricht ohne führende Nullen dem vorhandenen Gerät „007“. Hat Excel führende Nullen entfernt? Dann die Datei mit der Spalte Kennung als Text öffnen.'],
            self::row($preview, 2)['warnings'],
        );
        self::assertSame(
            ['Die Kennung „1.3.26“ sieht wie ein Datum aus. Hat Excel sie umgewandelt? Dann die Datei mit der Spalte Kennung als Text öffnen.'],
            self::row($preview, 3)['warnings'],
        );
        self::assertSame([], self::row($preview, 4)['warnings'], 'vorhandenes Gerät mit genau dieser Kennung');
        self::assertCount(1, self::row($preview, 5)['warnings']);
        self::assertSame([], self::row($preview, 6)['warnings']);
    }

    public function testOtherTenantIsNeverMatched(): void
    {
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'MA-01']);
        $this->createDeviceType($this->otherTenantId, ['name' => 'Funkgerät']);
        $this->createLocation($this->otherTenantId, ['name' => 'Wache Nord', 'kind' => 'room']);

        $preview = $this->previewOk(self::csv([
            ['MA-01', 'Atemschutzmaske', 'HLF 20'],
            ['MA-02', 'Funkgerät', 'Wache Nord'],
        ], 'Kennung;Gerätetyp;Standort'));

        self::assertSame('new', self::row($preview, 2)['result']);
        self::assertNull(self::row($preview, 2)['device']);
        self::assertSame(['device_types' => ['Funkgerät'], 'locations' => ['Wache Nord']], $preview['unknown']);
    }

    public function testWindows1252CommaAndTab(): void
    {
        $this->createViaApi('MA-01', ['manufacturer' => 'Dräger']);

        $cp1252 = $this->previewOk("Kennung,Hersteller,Ger\xE4tetyp\r\nMA-01,Dr\xE4ger,Atemschutzmaske\r\n");
        self::assertSame('windows-1252', $cp1252['encoding']);
        self::assertSame(',', $cp1252['delimiter']);
        self::assertSame('Komma', $cp1252['delimiter_label']);
        self::assertSame('unchanged', $cp1252['rows'][0]['result']);

        $tab = $this->previewOk("Kennung\tHersteller\nMA-01\tMSA\n");
        self::assertSame('utf-8', $tab['encoding']);
        self::assertSame('Tabulator', $tab['delimiter_label']);
        self::assertSame([['manufacturer', 'Dräger', 'MSA']], self::changes($tab['rows'][0]));
    }

    public function testFileErrorsAre400WithDetails(): void
    {
        $unknown = $this->preview("Kennung;Seriennumer\r\nMA-01;1\r\n");
        self::assertError(400, 'invalid_request', $unknown);
        self::assertSame(['line' => 1, 'columns' => ['Seriennumer']], self::json($unknown)['details']);

        $quote = $this->preview("Kennung;Notiz\r\nMA-01;\"offen\r\n");
        self::assertError(400, 'invalid_request', $quote);
        self::assertSame('Zeile 2: Ein Anführungszeichen wird nicht geschlossen.', self::json($quote)['message']);
        self::assertSame(['line' => 2], self::json($quote)['details']);

        $utf16 = $this->preview("\xFF\xFEK\0");
        self::assertError(400, 'invalid_request', $utf16);
        self::assertArrayNotHasKey('details', self::json($utf16));
    }

    public function testBodyIsChecked(): void
    {
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/import/preview', ['file' => 'kein base64!']));
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/import/preview', []));
        $unknownField = $this->api('POST', 'devices/import/preview', ['file' => base64_encode("Kennung\r\nMA-01\r\n"), 'comment' => 'x']);
        self::assertError(400, 'invalid_request', $unknownField);
        self::assertStringContainsString('"comment"', self::json($unknownField)['message']);
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/import/preview', ['file' => '']));

        $wrongType = $this->send('POST', self::url('immendingen', 'devices/import/preview'), "Kennung\r\nMA-01\r\n", $this->admin, ['content-type' => 'text/csv']);
        self::assertError(415, 'unsupported_media_type', $wrongType);
    }

    public function testSizeLimits(): void
    {
        $header = "Kennung;Notiz\r\n";
        $tooLarge = $this->preview($header . 'MA-01;' . str_repeat('x', 2_097_152));
        self::assertError(400, 'invalid_request', $tooLarge);
        self::assertSame(['max_bytes' => 2_097_152], self::json($tooLarge)['details'], 'die Route nimmt die Datei an, der Import lehnt sie ab');

        $body = $this->send('POST', self::url('immendingen', 'devices/import/preview'), '{"file":"' . str_repeat('A', 3_145_728) . '"}', $this->admin);
        self::assertError(400, 'invalid_request', $body);
        self::assertSame('Der Inhalt der Anfrage ist zu groß (höchstens 3145728 Byte).', self::json($body)['message']);
    }
}
