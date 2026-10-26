<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device\Import;

use FirefightingEquipment\Csv\CsvException;
use FirefightingEquipment\Csv\CsvFormat;
use FirefightingEquipment\Csv\CsvReader;
use FirefightingEquipment\Csv\CsvRecord;
use FirefightingEquipment\Csv\CsvTable;
use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceIdentifier;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\DeviceText;
use FirefightingEquipment\Device\Import\DeviceImportFile;
use FirefightingEquipment\Device\Import\ImportColumn;
use FirefightingEquipment\Device\Import\ImportDate;
use FirefightingEquipment\Device\Import\ImportFileException;
use FirefightingEquipment\Device\Import\ImportRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Lesen der Import-Datei ohne Datenbank: Kopfzeile, Grenzen, abgebrochener Export, Werte je Zeile.
 */
#[CoversClass(DeviceImportFile::class)]
#[CoversClass(ImportRow::class)]
#[CoversClass(ImportFileException::class)]
#[UsesClass(CsvReader::class)]
#[UsesClass(CsvRecord::class)]
#[UsesClass(CsvTable::class)]
#[UsesClass(CsvException::class)]
#[UsesClass(CsvFormat::class)]
#[UsesClass(ImportColumn::class)]
#[UsesClass(ImportDate::class)]
#[UsesClass(DeviceIdentifier::class)]
#[UsesClass(DeviceDate::class)]
#[UsesClass(DeviceText::class)]
final class DeviceImportFileTest extends TestCase
{
    private const string HEADER = "Kennung;Gerätetyp;Standort;Status;Nächste Prüfung;Seriennummer;Hersteller;Modell;Beschaffungsdatum;Notiz\r\n";

    public function testAllColumnsAreRead(): void
    {
        $file = DeviceImportFile::parse(CsvFormat::BOM . self::HEADER
            . "MA-01;Atemschutzmaske;HLF 20 / G1;in Wartung;01.03.2027;'-12;Dräger;\"FPS \"\"7000\"\"\";1.5.2024;\"Ventil\r\nprüfen\"\r\n");

        self::assertSame(ImportColumn::cases(), $file->columns);
        self::assertSame(hash('sha256', CsvFormat::BOM . self::HEADER
            . "MA-01;Atemschutzmaske;HLF 20 / G1;in Wartung;01.03.2027;'-12;Dräger;\"FPS \"\"7000\"\"\";1.5.2024;\"Ventil\r\nprüfen\"\r\n"), $file->hash);
        $row = $file->rows[0];
        self::assertSame(2, $row->line);
        self::assertSame('MA-01', $row->identifier?->value);
        self::assertSame('Atemschutzmaske', $row->deviceType);
        self::assertSame('HLF 20 / G1', $row->location);
        self::assertSame(DeviceStatus::Maintenance, $row->status);
        self::assertFalse($row->retired);
        self::assertSame('2027-03-01', $row->date(ImportColumn::NextInspectionDue)?->value);
        self::assertSame('-12', $row->text(ImportColumn::SerialNumber), 'Formelschutz entfernt');
        self::assertSame('Dräger', $row->text(ImportColumn::Manufacturer));
        self::assertSame('FPS "7000"', $row->text(ImportColumn::Model));
        self::assertSame('2024-05-01', $row->date(ImportColumn::ProcuredOn)?->value);
        self::assertSame("Ventil\nprüfen", $row->text(ImportColumn::Note), 'CRLF in der Notiz als LF');
        self::assertSame([], $row->errors);
    }

    public function testColumnOrderAndCaseDoNotMatter(): void
    {
        $file = DeviceImportFile::parse("notiz;KENNUNG;status\r\nx;MA-01;Defekt\r\n");

        self::assertSame([ImportColumn::Note, ImportColumn::Identifier, ImportColumn::Status], $file->columns);
        self::assertSame('MA-01', $file->rows[0]->identifier?->value);
        self::assertSame(DeviceStatus::Defective, $file->rows[0]->status);
        self::assertSame('x', $file->rows[0]->text(ImportColumn::Note));
    }

    public function testMissingColumnIsUnchangedAndEmptyCellClears(): void
    {
        $row = DeviceImportFile::parse("Kennung;Seriennummer;Nächste Prüfung;Gerätetyp;Standort;Status\r\nMA-01;;;;;\r\n")->rows[0];

        self::assertTrue($row->has(ImportColumn::SerialNumber));
        self::assertNull($row->text(ImportColumn::SerialNumber));
        self::assertTrue($row->has(ImportColumn::NextInspectionDue));
        self::assertNull($row->date(ImportColumn::NextInspectionDue));
        self::assertFalse($row->has(ImportColumn::Note));
        self::assertFalse($row->has(ImportColumn::ProcuredOn));
        self::assertNull($row->deviceType, 'leer = nicht angegeben');
        self::assertNull($row->location);
        self::assertNull($row->status);
        self::assertSame([], $row->errors);
    }

    public function testRetiredStatusMarksRow(): void
    {
        $row = DeviceImportFile::parse("Kennung;Status\r\nMA-01;Ausgesondert\r\n")->rows[0];

        self::assertTrue($row->retired);
        self::assertNull($row->status);
    }

    public function testValueErrorsAreCollectedPerRow(): void
    {
        $file = DeviceImportFile::parse("Kennung;Status;Nächste Prüfung;Beschaffungsdatum;Seriennummer\r\n"
            . "MA 01!;kaputt;31.02.2027;01.03.27;" . str_repeat('x', 101) . "\r\n"
            . ";einsatzbereit;;;\r\n");

        self::assertSame([
            'Die Kennung darf nur Buchstaben, Ziffern, Leerzeichen, Bindestrich (-), Schrägstrich (/) und Punkt (.) enthalten.',
            'Unbekannter Status „kaputt“ (möglich: einsatzbereit, in Wartung, defekt).',
            'Das Datum der nächsten Prüfung „31.02.2027“ ist kein gültiger Kalendertag.',
            'Die Seriennummer darf höchstens 100 Zeichen lang sein (angegeben: 101).',
            'Das Beschaffungsdatum „01.03.27“ muss ein Datum im Format TT.MM.JJJJ (oder JJJJ-MM-TT) sein.',
        ], $file->rows[0]->errors, 'Reihenfolge der Spalten im Export');
        self::assertSame('MA 01!', $file->rows[0]->identifierText);
        self::assertNull($file->rows[0]->identifier);
        self::assertSame(['Die Kennung fehlt.'], $file->rows[1]->errors);
    }

    public function testControlCharactersInSingleLineFieldAreErrors(): void
    {
        $row = DeviceImportFile::parse("Kennung;Modell\r\nMA-01;\"FPS\n7000\"\r\n")->rows[0];

        self::assertSame(['Das Modell darf keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.'], $row->errors);
    }

    public function testWrongFieldCountIsRowError(): void
    {
        $file = DeviceImportFile::parse("Kennung;Status\r\nMA-01;defekt;zu viel\r\nMA-02\r\nMA-03;defekt\r\n");

        self::assertSame(['Die Zeile hat 3 Felder, die Kopfzeile 2. Bitte Trennzeichen und Anführungszeichen prüfen.'], $file->rows[0]->errors);
        self::assertSame('MA-01', $file->rows[0]->identifierText);
        self::assertSame(['Die Zeile hat 1 Felder, die Kopfzeile 2. Bitte Trennzeichen und Anführungszeichen prüfen.'], $file->rows[1]->errors);
        self::assertSame([], $file->rows[2]->errors);
        self::assertSame([2, 3, 4], array_map(static fn (ImportRow $r): int => $r->line, $file->rows));
    }

    public function testWindows1252AndCommaAreDetected(): void
    {
        $file = DeviceImportFile::parse("Kennung,Hersteller\r\nMA-01,Dr\xE4ger\r\n");

        self::assertSame('windows-1252', $file->encoding);
        self::assertSame(',', $file->delimiter);
        self::assertSame('Dräger', $file->rows[0]->text(ImportColumn::Manufacturer));
    }

    public function testUnknownColumnsAreRejectedTogether(): void
    {
        $e = self::fileError("Kennung;Seriennumer;Farbe\r\nMA-01;1;rot\r\n");

        self::assertSame(
            'Unbekannte Spalten: „Seriennumer“, „Farbe“. Möglich sind: Kennung, Gerätetyp, Standort, Status, Nächste Prüfung, Seriennummer, Hersteller, Modell, Beschaffungsdatum, Notiz.',
            $e->getMessage(),
        );
        self::assertSame(['line' => 1, 'columns' => ['Seriennumer', 'Farbe']], $e->details);
    }

    public function testEmptyHeaderCellIsUnknownColumn(): void
    {
        $e = self::fileError("Kennung;;Status\r\nMA-01;;defekt\r\n");

        self::assertSame(['line' => 1, 'columns' => ['(Spalte 2 ohne Überschrift)']], $e->details);
    }

    public function testDuplicateColumnIsRejected(): void
    {
        $e = self::fileError("Kennung;Status;STATUS\r\nMA-01;defekt;defekt\r\n");

        self::assertSame('Die Spalte „Status“ kommt in der Kopfzeile mehrfach vor.', $e->getMessage());
    }

    public function testIdentifierColumnIsRequired(): void
    {
        $e = self::fileError("Gerätetyp;Status\r\nAtemschutzmaske;defekt\r\n");

        self::assertSame('Die Spalte „Kennung“ fehlt; sie ordnet die Zeilen den Geräten zu.', $e->getMessage());
    }

    public function testAbortedExportIsRejected(): void
    {
        $e = self::fileError(CsvFormat::BOM . self::HEADER . "MA-01;Atemschutzmaske;HLF 20;einsatzbereit;;;;;;\r\n" . CsvFormat::line([CsvFormat::ABORTED_LINE]));

        self::assertSame('Die Datei stammt aus einem abgebrochenen Export und ist unvollständig. Bitte den Export wiederholen.', $e->getMessage());
        self::assertSame(['line' => 3], $e->details);
    }

    public function testAbortedExportInWindows1252IsRejected(): void
    {
        $bytes = mb_convert_encoding("Kennung;Status\r\nMA-01;defekt\r\n" . CsvFormat::ABORTED_LINE . "\r\n", 'Windows-1252', 'UTF-8');

        self::assertSame(['line' => 3], self::fileError($bytes)->details);
    }

    public function testEmptyAndHeaderOnlyFilesAreRejected(): void
    {
        self::assertSame('Die Datei ist leer.', self::fileError('')->getMessage());
        self::assertSame('Die Datei enthält keine Geräte (nur die Kopfzeile).', self::fileError(self::HEADER . ";;;;;;;;;\r\n")->getMessage());
    }

    public function testUnreadableFileHasLine(): void
    {
        $e = self::fileError("Kennung;Status\r\nMA-01;\"defekt\r\n");

        self::assertSame('Zeile 2: Ein Anführungszeichen wird nicht geschlossen.', $e->getMessage());
        self::assertSame(['line' => 2], $e->details);
    }

    public function testRowLimit(): void
    {
        $rows = implode('', array_map(static fn (int $i): string => "MA-{$i}\r\n", range(1, DeviceImportFile::MAX_ROWS)));
        self::assertCount(DeviceImportFile::MAX_ROWS, DeviceImportFile::parse("Kennung\r\n" . $rows)->rows);

        $e = self::fileError("Kennung\r\n" . $rows . "MA-X\r\n");
        self::assertSame('Die Datei enthält 5001 Zeilen; höchstens 5000 sind möglich. Bitte in mehrere Dateien aufteilen.', $e->getMessage());
        self::assertSame(['rows' => 5001, 'max_rows' => 5000], $e->details);
    }

    public function testSizeLimit(): void
    {
        $header = "Kennung;Notiz\r\n";
        $bytes = $header . 'MA-01;' . str_repeat('x', DeviceImportFile::MAX_BYTES - strlen($header) - 6);
        self::assertSame(DeviceImportFile::MAX_BYTES, strlen($bytes));
        self::assertCount(1, DeviceImportFile::parse($bytes)->rows);

        $e = self::fileError($bytes . 'x');
        self::assertSame('Die Datei ist zu groß (2,1 MiB, höchstens 2 MiB). Bitte in mehrere Dateien aufteilen.', $e->getMessage());
        self::assertSame(['max_bytes' => DeviceImportFile::MAX_BYTES], $e->details);
    }

    private static function fileError(string $bytes): ImportFileException
    {
        try {
            DeviceImportFile::parse($bytes);
        } catch (ImportFileException $e) {
            return $e;
        }
        self::fail('Datei wurde angenommen.');
    }
}
