<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\User;

use FirefightingEquipment\User\InvalidUserValueException;
use FirefightingEquipment\User\PersonName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonName::class)]
#[UsesClass(InvalidUserValueException::class)]
final class PersonNameTest extends TestCase
{
    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('Jörg Müller-Lüdenscheidt', PersonName::fromString("  Jörg Müller-Lüdenscheidt \n")->value);
    }

    public function testMaximumLengthCountsCharacters(): void
    {
        self::assertSame(str_repeat('ä', 100), PersonName::fromString(str_repeat('ä', 100))->value);

        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessageIsOrContains('Der Name darf höchstens 100 Zeichen lang sein (angegeben: 101).');
        PersonName::fromString(str_repeat('ä', 101));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'leer' => ['', 'Der Name darf nicht leer sein.'];
        yield 'nur Leerraum' => [" \t\n ", 'Der Name darf nicht leer sein.'];
        yield 'kein UTF-8' => ["M\xFCller", 'Der Name ist kein gültiger UTF-8-Text.'];
        yield 'Zeilenumbruch innen' => ["Max\nMustermann", 'Der Name darf keine Steuerzeichen'];
        yield 'Tabulator innen' => ["Max\tMustermann", 'Der Name darf keine Steuerzeichen'];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidName(string $value, string $message): void
    {
        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessageIsOrContains($message);

        PersonName::fromString($value);
    }
}
