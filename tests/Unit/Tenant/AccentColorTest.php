<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Tenant;

use FirefightingEquipment\Tenant\AccentColor;
use FirefightingEquipment\Tenant\InvalidAccentColorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccentColor::class)]
#[CoversClass(InvalidAccentColorException::class)]
final class AccentColorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validInputs(): iterable
    {
        yield 'Kleinbuchstaben' => ['#b71c1c', '#b71c1c'];
        yield 'Großbuchstaben' => ['#B71C1C', '#b71c1c'];
        yield 'gemischt' => ['#B71c1C', '#b71c1c'];
        yield 'Leerraum am Rand' => ["  #B71C1C\n", '#b71c1c'];
        yield 'kurz' => ['#A33', '#aa3333'];
        yield 'kurz klein' => ['#000', '#000000'];
        yield 'Schwarz' => ['#000000', '#000000'];
        yield 'Grau knapp über der Grenze' => ['#767676', '#767676'];
        yield 'Rot knapp über der Grenze (4,50003)' => ['#ec1400', '#ec1400'];
    }

    #[DataProvider('validInputs')]
    public function testValidInputIsNormalized(string $input, string $expected): void
    {
        $color = AccentColor::fromString($input);

        self::assertSame($expected, $color->value);
        self::assertGreaterThanOrEqual(AccentColor::MIN_CONTRAST, $color->contrastWithWhite());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFormats(): iterable
    {
        yield 'leer' => [''];
        yield 'ohne #' => ['b71c1c'];
        yield 'Farbname' => ['red'];
        yield 'rgb()' => ['rgb(183, 28, 28)'];
        yield 'mit Alpha (8 Stellen)' => ['#b71c1cff'];
        yield 'mit Alpha (4 Stellen)' => ['#b71f'];
        yield '5 Stellen' => ['#b71c1'];
        yield 'kein Hex' => ['#b71c1g'];
        yield 'doppeltes #' => ['##b71c1c'];
        yield 'Leerzeichen innen' => ['# b71c1c'];
        yield 'Zeilenumbruch innen' => ["#b71\nc1c"];
        yield 'Vollbreite Ziffer' => ['#b71c1１'];
    }

    #[DataProvider('invalidFormats')]
    public function testInvalidFormatIsRejected(string $input): void
    {
        try {
            AccentColor::fromString($input);
            self::fail('Ausnahme erwartet.');
        } catch (InvalidAccentColorException $e) {
            self::assertSame('Die Akzentfarbe muss im Format #RRGGBB angegeben werden (z. B. #B71C1C).', $e->getMessage());
            self::assertNull($e->contrast);
        }
    }

    /**
     * @return iterable<string, array{string, float, string}>
     */
    public static function lowContrast(): iterable
    {
        yield 'Rot knapp unter der Grenze (4,49989)' => ['#ea1c05', 4.49, '4,49'];
        yield 'Grau knapp unter der Grenze' => ['#777777', 4.47, '4,47'];
        yield 'Weiß' => ['#ffffff', 1.0, '1,00'];
        yield 'Gelb' => ['#FFD600', 1.41, '1,41'];
    }

    #[DataProvider('lowContrast')]
    public function testTooLittleContrastIsRejectedWithReachedValue(string $input, float $contrast, string $shown): void
    {
        try {
            AccentColor::fromString($input);
            self::fail('Ausnahme erwartet.');
        } catch (InvalidAccentColorException $e) {
            self::assertSame($contrast, $e->contrast);
            self::assertSame(
                sprintf('Weiße Schrift auf %s erreicht nur einen Kontrast von %s:1; nötig sind mindestens 4,5:1. Bitte eine dunklere Farbe wählen.', strtoupper($input), $shown),
                $e->getMessage(),
            );
        }
    }

    public function testShownValueIsRoundedDownNeverUpToTheLimit(): void
    {
        self::assertSame(4.49, AccentColor::floorTwoDecimals(4.4999));
        self::assertSame(4.5, AccentColor::floorTwoDecimals(4.5));
        self::assertSame(6.57, AccentColor::floorTwoDecimals(6.5799));
    }

    public function testKnownContrastValues(): void
    {
        self::assertEqualsWithDelta(21.0, AccentColor::fromString('#000000')->contrastWithWhite(), 1e-9);
        self::assertEqualsWithDelta(4.5422, AccentColor::fromString('#767676')->contrastWithWhite(), 1e-4);
        self::assertEqualsWithDelta(4.50003, AccentColor::fromString('#ec1400')->contrastWithWhite(), 1e-5);
    }

    public function testDefaultColorMeetsTheRule(): void
    {
        $default = AccentColor::fromString(AccentColor::DEFAULT);

        self::assertSame(AccentColor::DEFAULT, $default->value);
        self::assertSame(AccentColor::DEFAULT, AccentColor::default()->value);
        self::assertEqualsWithDelta(6.57, $default->contrastWithWhite(), 0.01);
    }

    public function testStoredValueIsTakenWithoutContrastCheck(): void
    {
        self::assertSame('#ffffff', AccentColor::stored('#ffffff')->value);

        $this->expectException(\UnexpectedValueException::class);
        AccentColor::stored('#FFFFFF');
    }
}
