<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Csv;

use FirefightingEquipment\Csv\CsvFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvFormat::class)]
final class CsvFormatTest extends TestCase
{
    public function testLineUsesSemicolonAndCrlf(): void
    {
        self::assertSame("MA-01;Atemschutzmaske;;HLF 20 / G1\r\n", CsvFormat::line(['MA-01', 'Atemschutzmaske', null, 'HLF 20 / G1']));
    }

    public function testBomIsUtf8Bom(): void
    {
        self::assertSame('efbbbf', bin2hex(CsvFormat::BOM));
    }

    /**
     * @return iterable<string, array{string, string}> Wert, Feld in der Datei
     */
    public static function fields(): iterable
    {
        yield 'einfach' => ['Atemschutzmaske', 'Atemschutzmaske'];
        yield 'Umlaute unverändert' => ['Gerätehaus Süd, Straße', 'Gerätehaus Süd, Straße'];
        yield 'Leerzeichen ohne Anführungszeichen' => ['HLF 20 / G1', 'HLF 20 / G1'];
        yield 'Semikolon' => ['a;b', '"a;b"'];
        yield 'Anführungszeichen verdoppelt' => ['Maske "neu"', '"Maske ""neu"""'];
        yield 'LF' => ["Zeile 1\nZeile 2", "\"Zeile 1\nZeile 2\""];
        yield 'CR' => ["a\rb", "\"a\rb\""];
        yield 'leer' => ['', ''];
        yield 'Minus in der Mitte' => ['MA-01', 'MA-01'];
        yield 'Apostroph in der Mitte' => ["O'Brien", "O'Brien"];
    }

    #[DataProvider('fields')]
    public function testField(string $value, string $expected): void
    {
        self::assertSame($expected, CsvFormat::field($value));
    }

    public function testNullIsEmptyField(): void
    {
        self::assertSame('', CsvFormat::field(null));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formulas(): iterable
    {
        yield 'Gleichheitszeichen' => ['=HYPERLINK("http://x")', '"\'=HYPERLINK(""http://x"")"'];
        yield 'Plus' => ['+49 7462', "'+49 7462"];
        yield 'Minus' => ['-12', "'-12"];
        yield 'At' => ['@SUMME(A1)', "'@SUMME(A1)"];
        yield 'Tab' => ["\t=1", "'\t=1"];
        yield 'CR' => ["\r=1", "\"'\r=1\""];
        yield 'Apostroph' => ["'=1", "''=1"];
    }

    #[DataProvider('formulas')]
    public function testFormulaGuard(string $value, string $expected): void
    {
        self::assertSame($expected, CsvFormat::field($value));
    }

    #[DataProvider('formulas')]
    public function testFormulaGuardIsReversible(string $value, string $field): void
    {
        $unquoted = str_starts_with($field, '"') ? str_replace('""', '"', substr($field, 1, -1)) : $field;

        self::assertSame($value, CsvFormat::removeFormulaGuard($unquoted));
    }

    public function testRemoveFormulaGuardRemovesExactlyOne(): void
    {
        self::assertSame("'x", CsvFormat::removeFormulaGuard("''x"));
        self::assertSame('x', CsvFormat::removeFormulaGuard('x'));
        self::assertSame('', CsvFormat::removeFormulaGuard("'"));
    }
}
