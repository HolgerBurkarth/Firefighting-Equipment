<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\DeviceIdentifier;
use FirefightingEquipment\Device\InvalidDeviceValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceIdentifier::class)]
#[UsesClass(InvalidDeviceValueException::class)]
final class DeviceIdentifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalized(): iterable
    {
        yield 'unverändert' => ['MA-01', 'MA-01'];
        yield 'Groß-/Kleinschreibung bleibt' => ['ma-01', 'ma-01'];
        yield 'Rand' => ["  MA-01\t", 'MA-01'];
        yield 'mehrere Leerzeichen' => ['HLF   20 / G1', 'HLF 20 / G1'];
        yield 'Schrägstrich und Punkt' => ['1/2.3-a', '1/2.3-a'];
        yield 'Umlaute und ß' => ['Mäßig-Ü1', 'Mäßig-Ü1'];
        yield 'großes ẞ' => ['STRAẞE', 'STRAẞE'];
        yield 'andere Schriften' => ['Αθήνα-1', 'Αθήνα-1'];
        yield 'zerlegter Umlaut' => ["Ka\u{0308}fer", 'Käfer'];
        yield '30 Zeichen' => [str_repeat('ä', 30), str_repeat('ä', 30)];
    }

    #[DataProvider('normalized')]
    public function testNormalization(string $input, string $expected): void
    {
        self::assertSame($expected, DeviceIdentifier::fromString($input)->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'leer' => ['', 'darf nicht leer sein'];
        yield 'nur Leerzeichen' => ['   ', 'darf nicht leer sein'];
        yield '31 Zeichen' => [str_repeat('ä', 31), 'höchstens 30 Zeichen lang sein (angegeben: 31)'];
        yield 'Unterstrich' => ['MA_01', 'nur Buchstaben, Ziffern'];
        yield 'Tabulator innen' => ["MA\t01", 'nur Buchstaben, Ziffern'];
        yield 'Zeilenumbruch innen' => ["MA\n01", 'nur Buchstaben, Ziffern'];
        yield 'Sonderzeichen' => ['MA#1', 'nur Buchstaben, Ziffern'];
        yield 'andere Ziffern' => ['MA-٣', 'nur Buchstaben, Ziffern'];
        yield 'geschütztes Leerzeichen' => ["MA\u{00A0}01", 'nur Buchstaben, Ziffern'];
        yield 'kombinierender Akzent' => ["Cafe\u{0301}", 'kombinierende Zeichen'];
        yield 'ungültiges UTF-8' => ["MA-\xFF", 'kein gültiger UTF-8-Text'];
    }

    #[DataProvider('invalid')]
    public function testInvalidIdentifiersAreRejected(string $input, string $message): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        $this->expectExceptionMessage($message);

        DeviceIdentifier::fromString($input);
    }
}
