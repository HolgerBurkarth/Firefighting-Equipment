<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Csv;

use FirefightingEquipment\Csv\CsvException;
use FirefightingEquipment\Csv\CsvFormat;
use FirefightingEquipment\Csv\CsvReader;
use FirefightingEquipment\Csv\CsvRecord;
use FirefightingEquipment\Csv\CsvTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvReader::class)]
#[CoversClass(CsvRecord::class)]
#[CoversClass(CsvTable::class)]
#[CoversClass(CsvException::class)]
final class CsvReaderTest extends TestCase
{
    public function testUtf8WithBom(): void
    {
        $table = self::read(CsvFormat::BOM . "Kennung;Notiz\r\nMA-01;Größe M\r\n");

        self::assertSame(CsvReader::ENCODING_UTF8, $table->encoding);
        self::assertSame(';', $table->delimiter);
        self::assertSame(['Kennung', 'Notiz'], $table->header->fields);
        self::assertSame([[2, ['MA-01', 'Größe M']]], self::records($table));
    }

    public function testUtf8WithoutBom(): void
    {
        $table = self::read("Kennung;Notiz\nMA-01;Größe M\n");

        self::assertSame(CsvReader::ENCODING_UTF8, $table->encoding);
        self::assertSame([[2, ['MA-01', 'Größe M']]], self::records($table));
    }

    public function testWindows1252IsConverted(): void
    {
        // „Größe – 5 €“ in Windows-1252 (ö = F6, ß = DF, – = 96, € = 80)
        $table = self::read("Kennung;Notiz\r\nMA-01;Gr\xF6\xDFe \x96 5 \x80\r\n");

        self::assertSame(CsvReader::ENCODING_WINDOWS_1252, $table->encoding);
        self::assertSame([[2, ['MA-01', 'Größe – 5 €']]], self::records($table));
    }

    /**
     * @return iterable<string, array{string, string}> Datei, Teil der Meldung
     */
    public static function unreadableFiles(): iterable
    {
        yield 'UTF-16 LE' => ["\xFF\xFEK\0e\0", 'UTF-16'];
        yield 'UTF-16 BE' => ["\xFE\xFF\0K\0e", 'UTF-16'];
        yield 'UTF-16 ohne BOM' => ["K\0e\0n\0n\0", 'Null-Bytes'];
        yield 'in Windows-1252 nicht belegt' => ["Kennung\r\nMA\x81\r\n", 'weder UTF-8 noch Windows-1252'];
        yield 'BOM mit ungültigem UTF-8' => [CsvFormat::BOM . "Kennung\r\nGr\xF6\xDFe\r\n", 'ungültige UTF-8-Zeichen'];
        yield 'leer' => ['', 'leer'];
        yield 'nur Leerzeilen' => ["\r\n\r\n", 'leer'];
        yield 'nur BOM' => [CsvFormat::BOM, 'leer'];
        yield 'erste Zeile leer' => ["\r\nKennung\r\nMA-01\r\n", 'Spaltenüberschriften'];
    }

    #[DataProvider('unreadableFiles')]
    public function testUnreadableFileIsRejected(string $bytes, string $message): void
    {
        $this->expectException(CsvException::class);
        $this->expectExceptionMessage($message);

        self::read($bytes);
    }

    /**
     * @return iterable<string, array{string, string}> Datei, erkanntes Trennzeichen
     */
    public static function delimiters(): iterable
    {
        yield 'Semikolon' => ["Kennung;Status;Notiz\r\nMA-01;defekt;a,b\r\n", ';'];
        yield 'Komma' => ["Kennung,Status,Notiz\r\nMA-01,defekt,\"a;b\"\r\n", ','];
        yield 'Tab' => ["Kennung\tStatus\tNotiz\r\nMA-01\tdefekt\ta;b\r\n", "\t"];
        yield 'Komma mit Groß-/Kleinschreibung' => ["KENNUNG,status,notiz\r\nMA-01,defekt,x\r\n", ','];
        yield 'nur eine Spalte' => ["Kennung\r\nMA-01\r\n", ';'];
        yield 'unbekannte Überschriften' => ["A,B\r\n1,2\r\n", ';'];
    }

    #[DataProvider('delimiters')]
    public function testDelimiterIsDetectedFromHeader(string $bytes, string $delimiter): void
    {
        $table = self::read($bytes);

        self::assertSame($delimiter, $table->delimiter);
    }

    public function testDetectedDelimiterSplitsAllFields(): void
    {
        self::assertSame([[2, ['MA-01', 'defekt', 'a;b']]], self::records(self::read("Kennung,Status,Notiz\r\nMA-01,defekt,\"a;b\"\r\n")));
        self::assertSame([[2, ['MA-01', 'defekt', 'a;b']]], self::records(self::read("Kennung\tStatus\tNotiz\r\nMA-01\tdefekt\ta;b\r\n")));
    }

    public function testQuotedFields(): void
    {
        $table = self::read("Kennung;Notiz;Modell\r\n\"MA;01\";\"Zeile 1\r\nZeile 2\nZeile 3\";\"FPS \"\"7000\"\"\"\r\n\"\";\"\";x\r\n");

        self::assertSame([
            [2, ['MA;01', "Zeile 1\r\nZeile 2\nZeile 3", 'FPS "7000"']],
            [3, ['', '', 'x']],
        ], self::records($table));
    }

    public function testQuoteInsideUnquotedFieldIsKept(): void
    {
        self::assertSame([[2, ['MA"01', '5"']]], self::records(self::read("Kennung;Notiz\r\nMA\"01;5\"\r\n")));
    }

    public function testLineEndingsCrlfLfAndCr(): void
    {
        $table = self::read("Kennung;Notiz\r\nA;1\nB;2\rC;3");

        self::assertSame([[2, ['A', '1']], [3, ['B', '2']], [4, ['C', '3']]], self::records($table));
    }

    public function testEmptyRecordsAreSkippedButCounted(): void
    {
        $table = self::read("Kennung;Notiz\r\n\r\nA;1\r\n;\r\n;;\r\nB;2\r\n\r\n\r\n");

        self::assertSame([[3, ['A', '1']], [6, ['B', '2']]], self::records($table));
    }

    public function testLineNumbersCountRecordsNotPhysicalLines(): void
    {
        $table = self::read("Kennung;Notiz\r\nA;\"1\r\n2\r\n3\"\r\nB;x\r\n");

        self::assertSame([[2, ['A', "1\r\n2\r\n3"]], [3, ['B', 'x']]], self::records($table));
    }

    public function testTrailingDelimiterGivesEmptyField(): void
    {
        self::assertSame([[2, ['A', '']]], self::records(self::read("Kennung;Notiz\r\nA;")));
    }

    public function testUnclosedQuoteIsRejectedWithLine(): void
    {
        try {
            self::read("Kennung;Notiz\r\nA;1\r\nB;\"offen\r\nC;3\r\n");
            self::fail('Keine Ausnahme.');
        } catch (CsvException $e) {
            self::assertSame(3, $e->lineNumber);
            self::assertSame('Zeile 3: Ein Anführungszeichen wird nicht geschlossen.', $e->getMessage());
        }
    }

    public function testTextAfterClosingQuoteIsRejected(): void
    {
        try {
            self::read("Kennung;Notiz\r\n\"A\"x;1\r\n");
            self::fail('Keine Ausnahme.');
        } catch (CsvException $e) {
            self::assertSame(2, $e->lineNumber);
            self::assertStringContainsString('Nach einem schließenden Anführungszeichen', $e->getMessage());
        }
    }

    private static function read(string $bytes): CsvTable
    {
        return CsvReader::read($bytes, static fn (string $h): bool => in_array(mb_strtolower(trim($h)), ['kennung', 'status', 'notiz', 'modell'], true));
    }

    /**
     * @return list<array{int, list<string>}>
     */
    private static function records(CsvTable $table): array
    {
        return array_map(static fn (CsvRecord $r): array => [$r->line, $r->fields], $table->records);
    }
}
