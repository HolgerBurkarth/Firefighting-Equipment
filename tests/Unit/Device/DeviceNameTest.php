<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\DeviceNameRules;
use FirefightingEquipment\Device\DeviceTypeName;
use FirefightingEquipment\Device\InvalidDeviceValueException;
use FirefightingEquipment\Device\LocationName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceNameRules::class)]
#[CoversClass(DeviceTypeName::class)]
#[CoversClass(LocationName::class)]
#[UsesClass(InvalidDeviceValueException::class)]
final class DeviceNameTest extends TestCase
{
    public function testWhitespaceAtEdgesIsRemoved(): void
    {
        self::assertSame('Wärmebildkamera', DeviceTypeName::fromString("  Wärmebildkamera\t")->value);
        self::assertSame('HLF 20', LocationName::fromString(' HLF 20 ')->value);
    }

    public function testMaximumLengthCountsCharacters(): void
    {
        self::assertSame(str_repeat('ä', 50), DeviceTypeName::fromString(str_repeat('ä', 50))->value);
        self::assertSame(str_repeat('ä', 100), LocationName::fromString(str_repeat('ä', 100))->value);
    }

    public function testDeviceTypeNameLongerThan50IsRejected(): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        $this->expectExceptionMessage('höchstens 50 Zeichen lang sein (angegeben: 51)');

        DeviceTypeName::fromString(str_repeat('ä', 51));
    }

    public function testLocationNameLongerThan100IsRejected(): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        $this->expectExceptionMessage('höchstens 100 Zeichen lang sein (angegeben: 101)');

        LocationName::fromString(str_repeat('ä', 101));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'leer' => ['', 'Der Name darf nicht leer sein.'];
        yield 'nur Leerzeichen' => ['   ', 'Der Name darf nicht leer sein.'];
        yield 'Zeilenumbruch' => ["HLF\n20", 'Steuerzeichen'];
        yield 'Tabulator' => ["HLF\t20", 'Steuerzeichen'];
        yield 'NUL' => ["HLF\x0020", 'Steuerzeichen'];
        yield 'ungültiges UTF-8' => ["HLF \xFF", 'kein gültiger UTF-8-Text'];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidDeviceTypeNameIsRejected(string $value, string $message): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        $this->expectExceptionMessage($message);

        DeviceTypeName::fromString($value);
    }

    #[DataProvider('invalidNames')]
    public function testInvalidLocationNameIsRejected(string $value, string $message): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        $this->expectExceptionMessage($message);

        LocationName::fromString($value);
    }
}
