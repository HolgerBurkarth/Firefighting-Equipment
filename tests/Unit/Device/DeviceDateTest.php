<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\InvalidDeviceValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceDate::class)]
#[UsesClass(InvalidDeviceValueException::class)]
final class DeviceDateTest extends TestCase
{
    public function testValidDatesIncludingBounds(): void
    {
        self::assertSame('2027-03-01', DeviceDate::fromString('2027-03-01', 'Das Datum')->value);
        self::assertSame('2028-02-29', DeviceDate::fromString('2028-02-29', 'Das Datum')->value);
        self::assertSame(DeviceDate::MIN, DeviceDate::fromString('1950-01-01', 'Das Datum')->value);
        self::assertSame(DeviceDate::MAX, DeviceDate::fromString('2099-12-31', 'Das Datum')->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badFormat(): iterable
    {
        yield 'deutsch' => ['01.03.2027'];
        yield 'ohne führende Null' => ['2027-3-1'];
        yield 'mit Uhrzeit' => ['2027-03-01T00:00:00'];
        yield 'Zeilenumbruch' => ["2027-03-01\n"];
        yield '30. Februar' => ['2027-02-30'];
        yield '29. Februar ohne Schaltjahr' => ['2027-02-29'];
        yield 'Monat 13' => ['2027-13-01'];
        yield 'Tag 0' => ['2027-01-00'];
        yield 'leer' => [''];
    }

    #[DataProvider('badFormat')]
    public function testBadFormatIsRejected(string $value): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        $this->expectExceptionMessage('Das Beschaffungsdatum muss ein gültiges Datum im Format JJJJ-MM-TT sein.');

        DeviceDate::fromString($value, 'Das Beschaffungsdatum');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outOfRange(): iterable
    {
        yield 'Tippfehler 0202' => ['0202-05-01'];
        yield 'vor 1950' => ['1949-12-31'];
        yield 'nach 2099' => ['2100-01-01'];
    }

    #[DataProvider('outOfRange')]
    public function testOutOfRangeIsRejected(string $value): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        $this->expectExceptionMessage('Das Datum muss zwischen 1950-01-01 und 2099-12-31 liegen.');

        DeviceDate::fromString($value, 'Das Datum');
    }
}
