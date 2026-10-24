<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\User;

use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\InvalidUserValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailAddress::class)]
#[UsesClass(InvalidUserValueException::class)]
final class EmailAddressTest extends TestCase
{
    public function testTrimsAndLowercases(): void
    {
        self::assertSame('max.mustermann@example.org', EmailAddress::fromString("  Max.Mustermann@Example.ORG \n")->value);
    }

    public function testLowercasesUmlauts(): void
    {
        self::assertSame(
            'jörg.müller@feuerwehr-immendingen.de',
            EmailAddress::fromString('JÖRG.Müller@Feuerwehr-Immendingen.DE')->value,
        );
    }

    public function testSharpSIsNotTheSameAsDoubleS(): void
    {
        $sharp = EmailAddress::fromString('straße@example.org');
        $double = EmailAddress::fromString('strasse@example.org');

        self::assertSame('straße@example.org', $sharp->value);
        self::assertFalse($sharp->equals($double));
        self::assertTrue($sharp->equals(EmailAddress::fromString('STRAßE@example.org')));
    }

    public function testLocalPart(): void
    {
        self::assertSame('anna.schmidt+ff', EmailAddress::fromString('Anna.Schmidt+FF@example.org')->localPart());
    }

    public function testMaximumLength(): void
    {
        $domain = '@example.org';
        $maximal = str_repeat('a', EmailAddress::MAX_LENGTH - strlen($domain)) . $domain;

        self::assertSame($maximal, EmailAddress::fromString($maximal)->value);

        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessageIsOrContains('Die E-Mail-Adresse darf höchstens 254 Zeichen lang sein (angegeben: 255).');
        EmailAddress::fromString('a' . $maximal);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAddresses(): iterable
    {
        $format = 'Ungültige E-Mail-Adresse: erwartet wird die Form name@beispiel.de.';

        yield 'leer' => ['', 'Die E-Mail-Adresse darf nicht leer sein.'];
        yield 'nur Leerraum' => ["  \t ", 'Die E-Mail-Adresse darf nicht leer sein.'];
        yield 'kein UTF-8' => ["m\xFCller@example.org", 'Die E-Mail-Adresse ist kein gültiger UTF-8-Text.'];
        yield 'ohne @' => ['max.example.org', $format];
        yield 'zwei @' => ['max@feuerwehr@example.org', $format];
        yield 'doppeltes @' => ['max@@example.org', $format];
        yield 'lokaler Teil leer' => ['@example.org', $format];
        yield 'Domain leer' => ['max@', $format];
        yield 'Leerzeichen' => ['max mustermann@example.org', $format];
        yield 'geschütztes Leerzeichen' => ["max\u{00A0}mustermann@example.org", $format];
        yield 'Zeilenumbruch innen' => ["max\n@example.org", $format];
        yield 'Steuerzeichen' => ["max\x01@example.org", $format];
        yield 'unsichtbares Zeichen' => ["max\u{200B}@example.org", $format];
        yield 'Domain ohne Punkt' => ['max@localhost', $format];
        yield 'Domain mit Punkt am Anfang' => ['max@.example.org', $format];
        yield 'Domain mit Punkt am Ende' => ['max@example.org.', $format];
        yield 'Domain mit doppeltem Punkt' => ['max@example..org', $format];
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsInvalidAddress(string $value, string $message): void
    {
        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessageIsOrContains($message);

        EmailAddress::fromString($value);
    }
}
