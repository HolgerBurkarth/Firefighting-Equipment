<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\InvalidDeviceValueException;
use FirefightingEquipment\Device\ScanCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScanCode::class)]
final class ScanCodeTest extends TestCase
{
    public function testEmptyIsNoScanCode(): void
    {
        self::assertNull(ScanCode::fromString(null));
        self::assertNull(ScanCode::fromString(''));
        self::assertNull(ScanCode::fromString(" \t\r\n "));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function edges(): iterable
    {
        yield 'CR LF des Scanners' => ["4006381333931\r\n", '4006381333931'];
        yield 'Tab des Scanners' => ["4006381333931\t", '4006381333931'];
        yield 'Leerzeichen an beiden Enden' => ['  ABC 123  ', 'ABC 123'];
        yield 'Präfix und Suffix' => ["\t\nX\r", 'X'];
    }

    #[DataProvider('edges')]
    public function testEdgeWhitespaceIsRemoved(string $input, string $expected): void
    {
        self::assertSame($expected, ScanCode::fromString($input)?->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unchanged(): iterable
    {
        yield 'Groß- und Kleinschreibung' => ['AbC-xyz'];
        yield 'Leerzeichen im Inhalt (mehrfach)' => ['A  B   C'];
        yield 'GS1 mit GS' => ["0104006381333931\x1D10ABC123\x1D17271231"];
        yield 'ISO/IEC 15434 mit RS, GS, EOT' => ["[)>\x1E06\x1D17V12345\x1D1PABC\x1E\x04"];
        yield 'GS am Ende bleibt' => ["0104006381333931\x1D"];
        yield 'Symbologie-Kennung' => [']d20104006381333931'];
        yield 'URL eines QR-Codes' => ['https://example.org/g?id=4711&x=%20'];
        yield 'Umlaute und ß' => ['Straße-Größe'];
        yield 'Sonderzeichen' => ['=+-@\'";,'];
        yield 'führende Nullen' => ['0000123'];
    }

    #[DataProvider('unchanged')]
    public function testContentIsKeptUnchanged(string $value): void
    {
        self::assertSame($value, ScanCode::fromString($value)?->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'LF im Inhalt' => ["4006\n381", 'keine Steuerzeichen'];
        yield 'CR im Inhalt' => ["4006\r381", 'keine Steuerzeichen'];
        yield 'Tab im Inhalt' => ["4006\t381", 'keine Steuerzeichen'];
        yield 'NUL' => ["A\x00B", 'keine Steuerzeichen'];
        yield 'FS (0x1C)' => ["A\x1CB", 'keine Steuerzeichen'];
        yield 'US (0x1F)' => ["A\x1FB", 'keine Steuerzeichen'];
        yield 'ETX (0x03)' => ["A\x03B", 'keine Steuerzeichen'];
        yield 'ENQ (0x05)' => ["A\x05B", 'keine Steuerzeichen'];
        yield 'DEL' => ["A\x7FB", 'keine Steuerzeichen'];
        yield 'C1 (U+0085)' => ["A\u{0085}B", 'keine Steuerzeichen'];
        yield 'Zero-Width-Space' => ["A\u{200B}B", 'unsichtbare Formatzeichen'];
        yield 'BOM am Anfang' => ["\u{FEFF}4006381333931", 'unsichtbare Formatzeichen'];
        yield 'Soft Hyphen' => ["A\u{00AD}B", 'unsichtbare Formatzeichen'];
        yield 'Zeilentrenner U+2028' => ["A\u{2028}B", 'unsichtbare Formatzeichen'];
        yield 'zu lang' => [str_repeat('ä', 201), 'höchstens 200 Zeichen lang sein (angegeben: 201)'];
        yield 'kein UTF-8' => ["\xFF", 'kein gültiger UTF-8-Text'];
    }

    #[DataProvider('invalid')]
    public function testInvalidIsRejected(string $value, string $message): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        $this->expectExceptionMessage($message);

        ScanCode::fromString($value);
    }

    public function testMaximumLength(): void
    {
        self::assertSame(str_repeat('ä', 200), ScanCode::fromString(str_repeat('ä', 200))?->value);
        self::assertSame(str_repeat('x', 200), ScanCode::fromString(' ' . str_repeat('x', 200) . "\r\n")?->value, 'Rand zählt nicht');
    }

    public function testDisplayShowsSeparators(): void
    {
        self::assertSame('[)>[RS]06[GS]17V1[RS][EOT]', ScanCode::display("[)>\x1E06\x1D17V1\x1E\x04"));
        self::assertSame('ABC', ScanCode::display('ABC'));
    }
}
