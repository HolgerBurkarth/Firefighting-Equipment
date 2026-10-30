<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\ContentEncoding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentEncoding::class)]
final class ContentEncodingTest extends TestCase
{
    private const string FRONTEND = __DIR__ . '/../../../frontend';

    /**
     * @return iterable<string, array{string|null, ?ContentEncoding}>
     */
    public static function bothAvailable(): iterable
    {
        yield 'ohne Header' => [null, null];
        yield 'leer' => ['', null];
        yield 'Chrome' => ['gzip, deflate, br, zstd', ContentEncoding::Brotli];
        yield 'Firefox' => ['gzip, deflate, br', ContentEncoding::Brotli];
        yield 'nur gzip' => ['gzip', ContentEncoding::Gzip];
        yield 'x-gzip' => ['x-gzip', ContentEncoding::Gzip];
        yield 'nur deflate' => ['deflate', null];
        yield 'identity' => ['identity', null];
        yield 'Großschreibung' => ['GZIP, BR', ContentEncoding::Brotli];
        yield 'Leerraum' => [" gzip ;  q=0.5 ,\tbr ; q = 0.4 ", ContentEncoding::Gzip];
        yield 'br ausgeschlossen' => ['br;q=0, gzip', ContentEncoding::Gzip];
        yield 'beide ausgeschlossen' => ['br;q=0, gzip;q=0.000', null];
        yield 'gzip bevorzugt' => ['gzip;q=1, br;q=0.8', ContentEncoding::Gzip];
        yield 'Gleichstand: br' => ['gzip;q=0.5, br;q=0.5', ContentEncoding::Brotli];
        yield 'Stern' => ['*', ContentEncoding::Brotli];
        yield 'Stern ohne br' => ['br;q=0, *', ContentEncoding::Gzip];
        yield 'Stern ausgeschlossen' => ['*;q=0', null];
        yield 'Stern ausgeschlossen, gzip erlaubt' => ['*;q=0, gzip', ContentEncoding::Gzip];
        yield 'ungültiges Gewicht übergangen' => ['br;q=2, gzip', ContentEncoding::Gzip];
        yield 'Gewicht mit Buchstaben übergangen' => ['br;q=abc', null];
        yield 'zu viele Stellen übergangen' => ['br;q=0.1234, gzip;q=0.1', ContentEncoding::Gzip];
        yield 'erster Eintrag zählt' => ['br;q=0, br', null];
        yield 'andere Parameter egal' => ['br;foo=bar', ContentEncoding::Brotli];
        yield 'leere Einträge' => [',,br,', ContentEncoding::Brotli];
        yield 'ähnlicher Name' => ['brotli, gzipx', null];
    }

    #[DataProvider('bothAvailable')]
    public function testNegotiateWithBothVariants(?string $header, ?ContentEncoding $expected): void
    {
        self::assertSame($expected, ContentEncoding::negotiate($header, [ContentEncoding::Gzip, ContentEncoding::Brotli]));
    }

    public function testOnlyAvailableVariants(): void
    {
        self::assertSame(ContentEncoding::Gzip, ContentEncoding::negotiate('gzip, br', [ContentEncoding::Gzip]));
        self::assertNull(ContentEncoding::negotiate('br', [ContentEncoding::Gzip]));
        self::assertNull(ContentEncoding::negotiate('gzip, br', []));
    }

    public function testSuffixesMatchBuild(): void
    {
        $compress = (string) file_get_contents(self::FRONTEND . '/build/compress.ts');
        preg_match_all("/suffix: '([^']+)'/", $compress, $matches);

        self::assertSame(
            array_map(static fn (ContentEncoding $encoding): string => $encoding->fileSuffix(), ContentEncoding::cases()),
            $matches[1],
        );
        self::assertSame(['br', 'gz'], array_map(static fn (ContentEncoding $encoding): string => $encoding->etagSuffix(), ContentEncoding::cases()));
    }
}
