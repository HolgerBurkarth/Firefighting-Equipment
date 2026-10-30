<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Import;

use FirefightingEquipment\Api\V1\Devices\DeviceCsv;
use FirefightingEquipment\Api\V1\Devices\DeviceImportJson;
use FirefightingEquipment\Api\V1\Devices\DeviceImportTemplateHandler;
use FirefightingEquipment\Device\Import\DeviceImportPlanner;
use FirefightingEquipment\Device\Import\DeviceImportTemplate;
use FirefightingEquipment\Device\Import\ImportSuggestions;
use FirefightingEquipment\Http\CsvResponse;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Hilfen zum Erfassen per CSV: Vorlage mit Beispielzeilen (GET devices/import/template), „Gültige
 * Werte“ (GET devices/import/values), die Warnung zu den Beispielzeilen und „Meinten Sie …?“ bei
 * unbekannten Gerätetypen und Standorten in der Vorschau.
 */
#[CoversClass(DeviceImportTemplateHandler::class)]
#[CoversClass(DeviceImportTemplate::class)]
#[CoversClass(DeviceImportPlanner::class)]
#[CoversClass(DeviceImportJson::class)]
#[CoversClass(ImportSuggestions::class)]
final class DeviceImportTemplateTest extends ImportApiTestCase
{
    public function testTemplateHasImportColumnsAndTwoExampleRows(): void
    {
        $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $this->createDeviceType($this->tenantId, ['name' => 'Absturzsicherung', 'active' => 0]);

        [$response, $body] = $this->download('devices/import/template', $this->admin);

        self::assertSame(200, $response->statusCode(), $body);
        self::assertInstanceOf(CsvResponse::class, $response);
        self::assertSame('text/csv; charset=utf-8', $response->headers()['Content-Type']);
        self::assertSame('attachment; filename="geraete-vorlage-immendingen.csv"', $response->headers()['Content-Disposition']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        $rows = self::parse($body);
        self::assertSame(DeviceCsv::DEVICE_HEADER, $rows[0]);
        self::assertSame([
            ['BEISPIEL-1', '', 'Atemschutzmaske', 'HLF 20', 'einsatzbereit', '15.06.2027', 'SN-12345', 'Beispiel GmbH', 'Modell A', '15.06.2026', 'Beispielzeile – vor dem Import löschen'],
            ['BEISPIEL-2', '', 'Funkgerät', 'HLF 20 / G1', 'in Wartung', '', '', '', '', '', 'Beispielzeile – vor dem Import löschen'],
        ], array_slice($rows, 1));
    }

    public function testTemplateWithoutActiveTypesAndLocationsLeavesCellsEmpty(): void
    {
        $this->pdo()->prepare('UPDATE fe_device_types SET active = 0 WHERE tenant_id = ?')->execute([$this->tenantId]);
        $this->pdo()->prepare('UPDATE fe_locations SET active = 0 WHERE tenant_id = ?')->execute([$this->tenantId]);

        $rows = $this->rows('devices/import/template', $this->admin);

        self::assertSame(['BEISPIEL-1', 'BEISPIEL-2'], self::column($rows, 0));
        self::assertSame(['', ''], self::column($rows, 2));
        self::assertSame(['', ''], self::column($rows, 3));
    }

    public function testTemplateImportsButPreviewWarnsAboutExampleRows(): void
    {
        [, $body] = $this->download('devices/import/template', $this->admin);

        $preview = $this->previewOk($body);

        self::assertIsString($preview['token'], 'Beispielzeilen sind gültig');
        self::assertSame(['rows' => 2, 'new' => 2, 'changed' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0, 'warnings' => 2], $preview['summary']);
        self::assertSame(
            ['Die Kennung „BEISPIEL-1“ gehört zu den Beispielzeilen der Vorlage. Bitte die Zeile vor dem Import löschen.'],
            self::row($preview, 2)['warnings'],
        );
        self::assertSame(
            ['Die Kennung „BEISPIEL-2“ gehört zu den Beispielzeilen der Vorlage. Bitte die Zeile vor dem Import löschen.'],
            self::row($preview, 3)['warnings'],
        );
    }

    public function testExampleWarningAlsoForExistingDeviceInOtherSpelling(): void
    {
        $this->createViaApi('Beispiel-1');

        $preview = $this->previewOk(self::csv([['beispiel-1', 'Atemschutzmaske', 'HLF 20 / G1']], 'Kennung;Gerätetyp;Standort'));

        $row = self::row($preview, 2);
        self::assertSame('changed', $row['result'], 'nur die Schreibweise der Kennung');
        self::assertSame(
            ['Die Kennung „beispiel-1“ gehört zu den Beispielzeilen der Vorlage. Bitte die Zeile vor dem Import löschen.'],
            $row['warnings'],
        );
    }

    public function testValidValuesListActiveTypesLocationsStatusesAndFormats(): void
    {
        $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $this->createDeviceType($this->tenantId, ['name' => 'Absturzsicherung', 'active' => 0]);
        $this->createSubLocation($this->tenantId, $this->hlf, ['name' => 'G2', 'active' => 0]);
        $closed = $this->createLocation($this->tenantId, ['name' => 'Altbau', 'kind' => 'room', 'active' => 0]);
        $this->createSubLocation($this->tenantId, $closed, ['name' => 'Keller', 'kind' => 'room', 'active' => 0]);

        [$response, $body] = $this->download('devices/import/values', $this->admin);

        self::assertSame(200, $response->statusCode(), $body);
        self::assertSame('attachment; filename="gueltige-werte-immendingen-2026-06-15.csv"', $response->headers()['Content-Disposition']);
        self::assertSame([
            ['Spalte', 'Gültiger Wert'],
            ['Kennung', 'Pflicht, höchstens 30 Zeichen: Buchstaben, Ziffern, Leerzeichen und - / .'],
            ['Gerätetyp', 'Atemschutzmaske'],
            ['Gerätetyp', 'Funkgerät'],
            ['Standort', 'HLF 20'],
            ['Standort', 'HLF 20 / G1'],
            ['Standort', 'Lager'],
            ['Status', 'einsatzbereit'],
            ['Status', 'in Wartung'],
            ['Status', 'defekt'],
            ['Nächste Prüfung', 'Datum TT.MM.JJJJ, z. B. 01.03.2027'],
            ['Beschaffungsdatum', 'Datum TT.MM.JJJJ, z. B. 01.03.2027'],
        ], self::parse($body), 'nur aktive, nur die eigene Stadt (tuttlingen hat auch HLF 20)');
    }

    public function testUnknownValuesGetSuggestions(): void
    {
        $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät', 'active' => 0]);
        $this->createLocation($this->tenantId, ['name' => 'LF 10']);
        $this->createLocation($this->tenantId, ['name' => 'LF 16']);

        $preview = $this->previewOk(self::csv([
            ['MA-01', 'Atemschutzmaskee', 'HLF20'],
            ['MA-02', 'atemschutz maske', 'G1'],
            ['MA-03', 'Funkgeraet', 'Lagr'],
            ['MA-04', 'Atemschutzmaske', 'LF 1'],
            ['MA-05', 'Atemschutzmaske', 'Wache Nord'],
            ['MA-06', 'Atemschutzmaskee', 'HLF20'],
        ], 'Kennung;Gerätetyp;Standort'));

        self::assertSame([
            'Der Gerätetyp „Atemschutzmaskee“ ist nicht bekannt. Meinten Sie „Atemschutzmaske“?',
            'Der Standort „HLF20“ ist nicht bekannt. Meinten Sie „HLF 20“?',
        ], self::row($preview, 2)['messages']);
        self::assertSame([
            'Der Gerätetyp „atemschutz maske“ ist nicht bekannt. Meinten Sie „Atemschutzmaske“?',
            'Der Standort „G1“ ist nicht bekannt. Meinten Sie „HLF 20 / G1“?',
        ], self::row($preview, 3)['messages']);
        self::assertSame([
            'Der Gerätetyp „Funkgeraet“ ist nicht bekannt.',
            'Der Standort „Lagr“ ist nicht bekannt. Meinten Sie „Lager“?',
        ], self::row($preview, 4)['messages'], 'deaktivierte Gerätetypen werden nicht vorgeschlagen');
        self::assertSame(['Der Standort „LF 1“ ist nicht bekannt. Meinten Sie „LF 10“ oder „LF 16“?'], self::row($preview, 5)['messages']);
        self::assertSame(['Der Standort „Wache Nord“ ist nicht bekannt.'], self::row($preview, 6)['messages']);

        self::assertSame([
            'device_types' => ['Atemschutzmaskee', 'atemschutz maske', 'Funkgeraet'],
            'locations' => ['HLF20', 'G1', 'Lagr', 'LF 1', 'Wache Nord'],
        ], $preview['unknown']);
        self::assertSame([
            'device_types' => [
                ['value' => 'Atemschutzmaskee', 'suggestions' => ['Atemschutzmaske']],
                ['value' => 'atemschutz maske', 'suggestions' => ['Atemschutzmaske']],
            ],
            'locations' => [
                ['value' => 'HLF20', 'suggestions' => ['HLF 20']],
                ['value' => 'G1', 'suggestions' => ['HLF 20 / G1']],
                ['value' => 'Lagr', 'suggestions' => ['Lager']],
                ['value' => 'LF 1', 'suggestions' => ['LF 10', 'LF 16']],
            ],
        ], $preview['suggestions']);
    }

    public function testWithoutUnknownValuesSuggestionsAreEmpty(): void
    {
        $preview = $this->previewOk(self::csv([['MA-01', 'Atemschutzmaske', 'HLF 20 / G1']], 'Kennung;Gerätetyp;Standort'));

        self::assertSame(['device_types' => [], 'locations' => []], $preview['suggestions']);
    }
}
