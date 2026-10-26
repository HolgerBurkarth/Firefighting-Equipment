<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device\Import;

use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\Import\ImportDate;
use FirefightingEquipment\Device\InvalidDeviceValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportDate::class)]
#[UsesClass(DeviceDate::class)]
final class ImportDateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validDates(): iterable
    {
        yield 'TT.MM.JJJJ' => ['01.03.2027', '2027-03-01'];
        yield 'T.M.JJJJ' => ['1.3.2027', '2027-03-01'];
        yield 'gemischt' => ['01.3.2027', '2027-03-01'];
        yield 'JJJJ-MM-TT' => ['2027-03-01', '2027-03-01'];
        yield 'Schalttag' => ['29.02.2028', '2028-02-29'];
        yield 'untere Grenze' => ['01.01.1950', '1950-01-01'];
        yield 'obere Grenze' => ['31.12.2099', '2099-12-31'];
    }

    #[DataProvider('validDates')]
    public function testValid(string $value, string $iso): void
    {
        self::assertSame($iso, ImportDate::parse($value, 'Das Datum')->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'zweistelliges Jahr' => ['01.03.27', 'im Format TT.MM.JJJJ'];
        yield 'Schrägstriche' => ['01/03/2027', 'im Format TT.MM.JJJJ'];
        yield 'ISO ohne führende Null' => ['2027-3-1', 'im Format TT.MM.JJJJ'];
        yield 'Text' => ['März 2027', 'im Format TT.MM.JJJJ'];
        yield 'kein Kalendertag' => ['31.02.2027', 'kein gültiger Kalendertag'];
        yield 'kein Schalttag' => ['29.02.2027', 'kein gültiger Kalendertag'];
        yield 'zu früh' => ['31.12.1949', 'zwischen 01.01.1950 und 31.12.2099'];
        yield 'Tippfehler im Jahr' => ['01.03.0202', 'zwischen 01.01.1950 und 31.12.2099'];
        yield 'zu spät' => ['2100-01-01', 'zwischen 01.01.1950 und 31.12.2099'];
    }

    #[DataProvider('invalidDates')]
    public function testInvalid(string $value, string $message): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        $this->expectExceptionMessage($message);

        ImportDate::parse($value, 'Das Datum');
    }

    public function testMessageNamesSubjectAndValue(): void
    {
        $this->expectExceptionMessage('Das Beschaffungsdatum „31.02.2027“ ist kein gültiger Kalendertag.');

        ImportDate::parse('31.02.2027', 'Das Beschaffungsdatum');
    }
}
