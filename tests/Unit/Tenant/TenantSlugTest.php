<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Tenant;

use FirefightingEquipment\Tenant\InvalidTenantValueException;
use FirefightingEquipment\Tenant\TenantSlug;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantSlug::class)]
#[UsesClass(InvalidTenantValueException::class)]
final class TenantSlugTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validSlugs(): iterable
    {
        yield 'ein Buchstabe' => ['a'];
        yield 'eine Ziffer' => ['1'];
        yield 'Wort' => ['immendingen'];
        yield 'mit Bindestrich' => ['bad-duerrheim'];
        yield 'mehrere Bindestriche' => ['villingen-schwenningen-nord'];
        yield 'mit Ziffer' => ['stadt-2'];
        yield 'nur Ziffern' => ['123'];
        yield 'genau 50 Zeichen' => [str_repeat('a', 50)];
        yield 'enthält service' => ['service-stadt'];
        yield 'service als Teil' => ['myservice'];
    }

    #[DataProvider('validSlugs')]
    public function testValidSlugIsAccepted(string $value): void
    {
        self::assertSame($value, TenantSlug::fromString($value)->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugs(): iterable
    {
        yield 'Bindestrich am Anfang' => ['-immendingen'];
        yield 'Bindestrich am Ende' => ['immendingen-'];
        yield 'doppelter Bindestrich' => ['bad--duerrheim'];
        yield 'nur Bindestrich' => ['-'];
        yield 'Umlaut' => ['tübingen'];
        yield 'ß' => ['gießen'];
        yield 'Unterstrich' => ['bad_duerrheim'];
        yield 'Leerzeichen' => ['bad duerrheim'];
        yield 'Punkt' => ['stadt.de'];
        yield 'Schrägstrich' => ['a/b'];
        yield 'Leerzeichen am Rand' => [' immendingen '];
        yield 'Zeilenumbruch am Ende' => ["immendingen\n"];
        yield 'Großbuchstaben und Umlaut' => ['Tübingen'];
        yield 'Großbuchstaben und Unterstrich' => ['Bad_Duerrheim'];
    }

    #[DataProvider('invalidSlugs')]
    public function testInvalidSlugIsRejected(string $value): void
    {
        $this->expectException(InvalidTenantValueException::class);
        $this->expectExceptionMessage('Ungültiges Kürzel');

        TenantSlug::fromString($value);
    }

    public function testEmptySlugIsRejected(): void
    {
        $this->expectException(InvalidTenantValueException::class);
        $this->expectExceptionMessage('Das Kürzel darf nicht leer sein.');

        TenantSlug::fromString('');
    }

    public function testSlugLongerThan50IsRejected(): void
    {
        $this->expectException(InvalidTenantValueException::class);
        $this->expectExceptionMessage('höchstens 50 Zeichen lang sein (angegeben: 51)');

        TenantSlug::fromString(str_repeat('a', 51));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uppercaseSlugs(): iterable
    {
        yield 'erster Buchstabe groß' => ['Immendingen', 'immendingen'];
        yield 'alles groß' => ['IMMENDINGEN', 'immendingen'];
        yield 'mit Bindestrich' => ['Bad-Duerrheim', 'bad-duerrheim'];
    }

    #[DataProvider('uppercaseSlugs')]
    public function testUppercaseIsRejectedWithSuggestion(string $value, string $suggestion): void
    {
        $this->expectException(InvalidTenantValueException::class);
        $this->expectExceptionMessage(sprintf(
            'Das Kürzel darf keine Großbuchstaben enthalten. Gemeint ist vermutlich „%s“.',
            $suggestion,
        ));

        TenantSlug::fromString($value);
    }

    #[DataProvider('uppercaseSlugs')]
    public function testUppercaseExceptionCarriesSuggestion(string $value, string $suggestion): void
    {
        try {
            TenantSlug::fromString($value);
            self::fail('Keine Ausnahme.');
        } catch (InvalidTenantValueException $e) {
            self::assertSame($suggestion, $e->suggestion);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function suggestionlessSlugs(): iterable
    {
        yield 'leer' => [''];
        yield 'zu lang' => [str_repeat('A', 51)];
        yield 'reserviert groß' => ['Service'];
        yield 'Großbuchstaben und Umlaut' => ['Tübingen'];
        yield 'nur Format' => ['bad--duerrheim'];
    }

    #[DataProvider('suggestionlessSlugs')]
    public function testOtherErrorsHaveNoSuggestion(string $value): void
    {
        try {
            TenantSlug::fromString($value);
            self::fail('Keine Ausnahme.');
        } catch (InvalidTenantValueException $e) {
            self::assertNull($e->suggestion);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedSlugs(): iterable
    {
        yield 'service' => ['service'];
        yield 'SERVICE' => ['SERVICE'];
        yield 'Service' => ['Service'];
    }

    #[DataProvider('reservedSlugs')]
    public function testReservedSlugIsRejected(string $value): void
    {
        $this->expectException(InvalidTenantValueException::class);
        $this->expectExceptionMessage('Das Kürzel „service“ ist reserviert.');

        TenantSlug::fromString($value);
    }

    public function testEquals(): void
    {
        self::assertTrue(TenantSlug::fromString('immendingen')->equals(TenantSlug::fromString('immendingen')));
        self::assertFalse(TenantSlug::fromString('immendingen')->equals(TenantSlug::fromString('tuttlingen')));
    }
}
