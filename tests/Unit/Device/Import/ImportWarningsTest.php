<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device\Import;

use FirefightingEquipment\Device\Import\ImportWarnings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportWarnings::class)]
final class ImportWarningsTest extends TestCase
{
    public function testWithoutLeadingZeros(): void
    {
        self::assertSame('7', ImportWarnings::withoutLeadingZeros('007'));
        self::assertSame('7', ImportWarnings::withoutLeadingZeros('7'));
        self::assertSame('100', ImportWarnings::withoutLeadingZeros('0100'));
        self::assertSame('0', ImportWarnings::withoutLeadingZeros('000'));
        self::assertNull(ImportWarnings::withoutLeadingZeros('MA-007'));
        self::assertNull(ImportWarnings::withoutLeadingZeros('1.5'));
        self::assertNull(ImportWarnings::withoutLeadingZeros('١٢'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dates(): iterable
    {
        yield 'deutsch' => ['01.03.2026'];
        yield 'deutsch kurz' => ['1.3.26'];
        yield 'ISO' => ['2026-03-01'];
        yield 'US' => ['3/1/2026'];
        yield 'Excel deutsch Tag und Monat' => ['01. Mrz'];
        yield 'Excel deutsch mit Jahr' => ['01. März 2026'];
        yield 'Excel englisch' => ['1-Mar'];
        yield 'Monat und Jahr' => ['Mrz 26'];
        yield 'Monat und Jahr englisch' => ['Mar-26'];
        yield 'Monatsname klein' => ['15. dez'];
    }

    #[DataProvider('dates')]
    public function testLooksLikeDate(string $identifier): void
    {
        self::assertTrue(ImportWarnings::looksLikeDate($identifier));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noDates(): iterable
    {
        yield 'Kennung' => ['MA-01'];
        yield 'Ziffern' => ['12345'];
        yield 'Versionsnummer' => ['1.2'];
        yield 'Fahrzeug' => ['HLF 20/1'];
        yield 'Wort nach Zahl' => ['12 Augsburg'];
        yield 'drei Teile mit Buchstaben' => ['1.A.2026'];
    }

    #[DataProvider('noDates')]
    public function testDoesNotLookLikeDate(string $identifier): void
    {
        self::assertFalse(ImportWarnings::looksLikeDate($identifier));
    }
}
