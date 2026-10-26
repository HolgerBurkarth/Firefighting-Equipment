<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\DeviceField;
use FirefightingEquipment\Device\DeviceText;
use FirefightingEquipment\Device\InvalidDeviceValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceText::class)]
#[UsesClass(DeviceField::class)]
#[UsesClass(InvalidDeviceValueException::class)]
final class DeviceTextTest extends TestCase
{
    public function testTrimmedAndEmptyBecomesNull(): void
    {
        self::assertSame('Dräger', DeviceText::field('  Dräger ', DeviceField::Manufacturer));
        self::assertNull(DeviceText::field('   ', DeviceField::Model));
        self::assertNull(DeviceText::field(null, DeviceField::SerialNumber));
        self::assertNull(DeviceText::comment(''));
        self::assertNull(DeviceText::search(' '));
    }

    public function testNoteKeepsLineBreaksAsLf(): void
    {
        self::assertSame("Zeile 1\nZeile 2\n\nZeile 4", DeviceText::field("\r\nZeile 1\r\nZeile 2\r\rZeile 4\n", DeviceField::Note));
    }

    public function testMaximumLengthsCountCharacters(): void
    {
        self::assertSame(str_repeat('ä', 100), DeviceText::field(str_repeat('ä', 100), DeviceField::SerialNumber));
        self::assertSame(str_repeat('ä', 1000), DeviceText::field(str_repeat('ä', 1000), DeviceField::Note));
        self::assertSame(str_repeat('ä', 500), DeviceText::comment(str_repeat('ä', 500)));
        self::assertSame(str_repeat('ä', 100), DeviceText::search(str_repeat('ä', 100)));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalid(): iterable
    {
        yield 'Seriennummer zu lang' => [
            static fn (): ?string => DeviceText::field(str_repeat('x', 101), DeviceField::SerialNumber),
            'Die Seriennummer darf höchstens 100 Zeichen lang sein (angegeben: 101).',
        ];
        yield 'Hersteller zu lang' => [
            static fn (): ?string => DeviceText::field(str_repeat('x', 101), DeviceField::Manufacturer),
            'Der Hersteller darf höchstens 100 Zeichen lang sein',
        ];
        yield 'Notiz zu lang' => [
            static fn (): ?string => DeviceText::field(str_repeat('x', 1001), DeviceField::Note),
            'Die Notiz darf höchstens 1000 Zeichen lang sein (angegeben: 1001).',
        ];
        yield 'Kommentar zu lang' => [
            static fn (): ?string => DeviceText::comment(str_repeat('x', 501)),
            'Der Kommentar darf höchstens 500 Zeichen lang sein (angegeben: 501).',
        ];
        yield 'Suchtext zu lang' => [
            static fn (): ?string => DeviceText::search(str_repeat('x', 101)),
            'Der Suchtext darf höchstens 100 Zeichen lang sein',
        ];
        yield 'Zeilenumbruch im Modell' => [
            static fn (): ?string => DeviceText::field("FPS\n7000", DeviceField::Model),
            'Das Modell darf keine Steuerzeichen',
        ];
        yield 'Zeilenumbruch im Kommentar' => [
            static fn (): ?string => DeviceText::comment("Prüfung\nbestanden"),
            'Der Kommentar darf keine Steuerzeichen',
        ];
        yield 'Tabulator in der Notiz' => [
            static fn (): ?string => DeviceText::field("a\tb", DeviceField::Note),
            'Die Notiz darf außer Zeilenumbrüchen keine Steuerzeichen',
        ];
        yield 'NUL in der Notiz' => [
            static fn (): ?string => DeviceText::field("a\x00b", DeviceField::Note),
            'Die Notiz darf außer Zeilenumbrüchen keine Steuerzeichen',
        ];
        yield 'ungültiges UTF-8' => [
            static fn (): ?string => DeviceText::field("\xFF", DeviceField::Manufacturer),
            'Der Hersteller ist kein gültiger UTF-8-Text.',
        ];
    }

    /**
     * @param \Closure(): mixed $call
     */
    #[DataProvider('invalid')]
    public function testInvalidTextsAreRejected(\Closure $call, string $message): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        $this->expectExceptionMessage($message);

        $call();
    }

    public function testOnlyTextFieldsAreAccepted(): void
    {
        $this->expectException(\LogicException::class);

        DeviceText::field('x', DeviceField::ProcuredOn);
    }
}
