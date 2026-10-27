<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Tenant\Logo;

use FirefightingEquipment\Tenant\Logo\InvalidLogoException;
use FirefightingEquipment\Tenant\Logo\PngInspector;
use FirefightingEquipment\Tests\Support\TestPng;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PngInspector::class)]
#[CoversClass(InvalidLogoException::class)]
final class PngInspectorTest extends TestCase
{
    public function testValidPngReturnsSize(): void
    {
        self::assertSame([512, 512], PngInspector::inspect(TestPng::create()));
        self::assertSame([512, 512], PngInspector::inspect(TestPng::create(palette: true)));
    }

    public function testMetadataChunksAreAllowedInTheUpload(): void
    {
        // Entfernt werden sie erst beim Neuerzeugen (LogoRenderer).
        $png = TestPng::withChunk(TestPng::create(), 'tEXt', "Comment\0geheim");

        self::assertSame([512, 512], PngInspector::inspect($png));
    }

    /**
     * @return iterable<string, array{\Closure(): string, string}>
     */
    public static function invalidFiles(): iterable
    {
        $png = static fn (): string => TestPng::create();
        $jpeg = static function (): string {
            $stream = fopen('php://memory', 'w+b') ?: throw new \RuntimeException();
            imagejpeg(imagecreatetruecolor(512, 512) ?: throw new \RuntimeException(), $stream);
            rewind($stream);

            return (string) stream_get_contents($stream);
        };

        yield 'leer' => [static fn (): string => '', 'Die Datei ist leer.'];
        yield 'Text' => [static fn (): string => 'Hallo Welt', 'Die Datei ist keine PNG-Datei. Bitte das Logo als PNG hochladen.'];
        yield 'JPEG' => [$jpeg, 'Die Datei ist keine PNG-Datei. Bitte das Logo als PNG hochladen.'];
        yield 'nur Signatur' => [static fn (): string => PngInspector::SIGNATURE, 'Die PNG-Datei ist unvollständig.'];
        yield 'abgeschnitten' => [static fn (): string => substr($png(), 0, -20), 'Die PNG-Datei ist unvollständig.'];
        yield 'ohne IEND' => [static fn (): string => substr($png(), 0, -12), 'Die PNG-Datei ist unvollständig.'];
        yield 'falsche CRC' => [static function () use ($png): string {
            $bytes = $png();
            $bytes[29] = chr(ord($bytes[29]) ^ 0xFF);

            return $bytes;
        }, 'Die PNG-Datei ist beschädigt.'];
        yield 'IHDR nicht zuerst' => [static fn (): string => PngInspector::SIGNATURE . TestPng::chunk('tEXt', 'a') . substr($png(), 8), 'Die PNG-Datei ist beschädigt.'];
        yield 'IHDR doppelt' => [static fn (): string => TestPng::withChunk($png(), 'IHDR', substr($png(), 16, 13)), 'Die PNG-Datei ist beschädigt.'];
        yield 'ungültiger Chunk-Typ' => [static fn (): string => TestPng::withChunk($png(), 'ab1d', ''), 'Die PNG-Datei ist beschädigt.'];
        yield 'ohne IDAT' => [static fn (): string => substr($png(), 0, 33) . TestPng::chunk('IEND', ''), 'Die PNG-Datei ist beschädigt.'];
        yield 'falsche Bittiefe' => [static fn (): string => PngInspector::SIGNATURE . TestPng::chunk('IHDR', pack('NN', 512, 512) . "\x03\x06\0\0\0") . substr($png(), 33), 'Die PNG-Datei ist beschädigt.'];
        yield 'angehängte Daten' => [static fn (): string => $png() . 'PK' . str_repeat("\0", 20), 'Nach dem Ende der PNG-Datei folgen weitere Daten. Bitte die Datei mit einem Bildprogramm neu speichern.'];
        yield 'angehängtes zweites PNG' => [static fn (): string => $png() . $png(), 'Nach dem Ende der PNG-Datei folgen weitere Daten. Bitte die Datei mit einem Bildprogramm neu speichern.'];
        yield 'animiert' => [static fn (): string => TestPng::withChunk($png(), 'acTL', pack('NN', 2, 0)), 'Animierte PNG-Dateien werden nicht unterstützt.'];
        yield 'nicht quadratisch' => [static fn (): string => TestPng::create(512, 400), 'Das Logo muss quadratisch sein (hochgeladen: 512 × 400 px).'];
        yield 'zu klein' => [static fn (): string => TestPng::create(511), 'Das Logo muss genau 512 × 512 px groß sein (hochgeladen: 511 × 511 px).'];
        yield 'zu groß' => [static fn (): string => TestPng::create(513), 'Das Logo muss genau 512 × 512 px groß sein (hochgeladen: 513 × 513 px).'];
        yield 'riesig laut IHDR (nie dekodiert)' => [
            static fn (): string => PngInspector::SIGNATURE . TestPng::chunk('IHDR', pack('NN', 60000, 60000) . "\x08\x06\0\0\0") . substr($png(), 33),
            'Das Logo muss genau 512 × 512 px groß sein (hochgeladen: 60000 × 60000 px).',
        ];
    }

    /**
     * @param \Closure(): string $file
     */
    #[DataProvider('invalidFiles')]
    public function testInvalidFileIsRejected(\Closure $file, string $message): void
    {
        $this->expectException(InvalidLogoException::class);
        $this->expectExceptionMessage($message);

        PngInspector::inspect($file());
    }

    public function testTooLargeFileIsRejectedWithDetails(): void
    {
        $png = TestPng::create();
        $padded = TestPng::withChunk($png, 'tEXt', str_repeat('x', PngInspector::MAX_BYTES - strlen($png) - 12 + 1));
        self::assertSame(PngInspector::MAX_BYTES + 1, strlen($padded));

        try {
            PngInspector::inspect($padded);
            self::fail('Ausnahme erwartet.');
        } catch (InvalidLogoException $e) {
            self::assertSame('Das Logo ist zu groß (500 KB); erlaubt sind höchstens 500 KB.', $e->getMessage());
            self::assertSame(['bytes' => 512_001, 'max_bytes' => 512_000], $e->details);
        }

        $exact = TestPng::withChunk($png, 'tEXt', str_repeat('x', PngInspector::MAX_BYTES - strlen($png) - 12));
        self::assertSame([512, 512], PngInspector::inspect($exact), 'genau 500 KB sind erlaubt');
    }

    public function testSizeErrorsCarryDetails(): void
    {
        try {
            PngInspector::inspect(TestPng::create(511));
            self::fail('Ausnahme erwartet.');
        } catch (InvalidLogoException $e) {
            self::assertSame(['width' => 511, 'height' => 511, 'min_edge' => 512, 'max_edge' => 512], $e->details);
        }
        try {
            PngInspector::inspect(TestPng::create(512, 400));
            self::fail('Ausnahme erwartet.');
        } catch (InvalidLogoException $e) {
            self::assertSame(['width' => 512, 'height' => 400], $e->details);
        }
    }
}
