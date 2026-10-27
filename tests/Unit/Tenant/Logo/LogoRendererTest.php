<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Tenant\Logo;

use FirefightingEquipment\Tenant\Logo\InvalidLogoException;
use FirefightingEquipment\Tenant\Logo\LogoImages;
use FirefightingEquipment\Tenant\Logo\LogoRenderer;
use FirefightingEquipment\Tenant\Logo\LogoSize;
use FirefightingEquipment\Tenant\Logo\PngInspector;
use FirefightingEquipment\Tests\Support\TestPng;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LogoRenderer::class)]
#[CoversClass(LogoImages::class)]
#[CoversClass(LogoSize::class)]
#[CoversClass(PngInspector::class)]
#[CoversClass(InvalidLogoException::class)]
final class LogoRendererTest extends TestCase
{
    /** Was GD schreibt: Bilddaten und Auflösung (pHYs), keine Metadaten. */
    private const array ALLOWED_CHUNKS = ['IHDR', 'pHYs', 'IDAT', 'IEND'];

    /**
     * @return iterable<string, array{bool}>
     */
    public static function sources(): iterable
    {
        yield 'Truecolor mit Alpha' => [false];
        yield 'Palette mit tRNS' => [true];
    }

    #[DataProvider('sources')]
    public function testCreatesAllSizesAndKeepsTransparency(bool $palette): void
    {
        $images = LogoRenderer::fromUpload(TestPng::create(palette: $palette));

        foreach (LogoSize::cases() as $size) {
            $png = $images->png($size);
            $chunks = TestPng::chunkTypes($png);
            self::assertSame(['IHDR', 'IEND'], [$chunks[0], $chunks[count($chunks) - 1]], (string) $size->value);
            $info = getimagesizefromstring($png);
            self::assertIsArray($info);
            self::assertSame([$size->value, $size->value, 'image/png'], [$info[0], $info[1], $info['mime']]);

            $center = intdiv($size->value, 2);
            self::assertSame([0, 0xC80000], TestPng::pixel($png, $center, $center), 'Mitte deckend rot');
            if ($size->opaque()) {
                self::assertSame([0, 0xFFFFFF], TestPng::pixel($png, 0, 0), 'Apple: Ecke deckend weiß');
            } else {
                self::assertSame(127, TestPng::pixel($png, 0, 0)[0], 'Ecke transparent');
            }
        }
    }

    public function testMetadataAndHiddenContentAreRemoved(): void
    {
        $upload = TestPng::create();
        foreach (['tEXt' => "Comment\0geheim", 'iTXt' => "Author\0\0\0de\0\0Erika", 'zTXt' => "x\0\0" . gzcompress('geheim'), 'eXIf' => 'MM' . str_repeat("\0", 10), 'tIME' => pack('nCCCCC', 2026, 1, 1, 0, 0, 0)] as $type => $data) {
            $upload = TestPng::withChunk($upload, $type, $data);
        }
        // Privater Chunk (kleingeschriebener Typ) mit beliebigem Inhalt
        $upload = TestPng::withChunk($upload, 'prVt', '<?php echo 1; ?>');

        $images = LogoRenderer::fromUpload($upload);

        foreach (LogoSize::cases() as $size) {
            $png = $images->png($size);
            self::assertSame([], array_values(array_diff(TestPng::chunkTypes($png), self::ALLOWED_CHUNKS)), (string) $size->value);
            self::assertStringNotContainsString('geheim', $png);
            self::assertStringNotContainsString('<?php', $png);
        }
    }

    public function testVersionDependsOnContent(): void
    {
        $a = LogoRenderer::fromUpload(TestPng::create());
        $b = LogoRenderer::fromUpload(TestPng::create(red: 201));

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}\z/', $a->version());
        self::assertSame($a->version(), LogoRenderer::fromUpload(TestPng::create())->version(), 'gleiches Bild, gleiche Version');
        self::assertNotSame($a->version(), $b->version());
    }

    public function testUndecodableDataIsRejected(): void
    {
        // Gültige Chunk-Struktur, aber IDAT enthält keine zlib-Daten.
        $broken = substr(TestPng::create(), 0, 33) . TestPng::chunk('IDAT', 'keine Bilddaten') . TestPng::chunk('IEND', '');

        $this->expectException(InvalidLogoException::class);
        $this->expectExceptionMessage('Die PNG-Datei kann nicht gelesen werden.');
        LogoRenderer::fromUpload($broken);
    }

    public function testRulesOfInspectorApply(): void
    {
        $this->expectException(InvalidLogoException::class);
        $this->expectExceptionMessage('Nach dem Ende der PNG-Datei folgen weitere Daten.');
        LogoRenderer::fromUpload(TestPng::create() . 'x');
    }

    public function testImagesRequireExactlyAllSizes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LogoImages([512 => 'a', 192 => 'b']);
    }
}
