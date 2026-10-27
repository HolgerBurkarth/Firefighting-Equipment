<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http\Frontend;

use FirefightingEquipment\Http\CacheableResponse;
use FirefightingEquipment\Http\Frontend\FrontendAssets;
use FirefightingEquipment\Http\Frontend\FrontendFile;
use FirefightingEquipment\Http\Frontend\FrontendUnavailableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Vertrag mit dem Frontend (frontend/index.html, frontend/build/appBase.ts) und Einsetzen des
 * Basispfads.
 */
#[CoversClass(FrontendAssets::class)]
#[CoversClass(FrontendFile::class)]
#[CoversClass(FrontendUnavailableException::class)]
final class FrontendAssetsTest extends TestCase
{
    private const string FRONTEND = __DIR__ . '/../../../../frontend';

    public function testPlaceholderMatchesFrontendSource(): void
    {
        $indexHtml = (string) file_get_contents(self::FRONTEND . '/index.html');
        $appBase = (string) file_get_contents(self::FRONTEND . '/build/appBase.ts');

        self::assertSame(1, substr_count($indexHtml, FrontendAssets::BASE_PLACEHOLDER));
        self::assertStringContainsString(sprintf('<base href="%s" />', FrontendAssets::BASE_PLACEHOLDER), $indexHtml);
        self::assertStringContainsString(sprintf("export const BASE_PLACEHOLDER = '%s';", FrontendAssets::BASE_PLACEHOLDER), $appBase);
    }

    public function testIndexHtmlWithBasePath(): void
    {
        $file = new FrontendAssets(__DIR__ . '/../../../Fixtures/frontend-dist')->indexHtml('/firefighting/test/');

        self::assertSame(CacheableResponse::HTML, $file->contentType);
        self::assertStringContainsString('<base href="/firefighting/test/" />', $file->body);
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"\z/', $file->etag());
    }

    public function testBasePathIsEscaped(): void
    {
        $file = new FrontendAssets(__DIR__ . '/../../../Fixtures/frontend-dist')->indexHtml('/"><x/');

        self::assertStringContainsString('<base href="/&quot;&gt;&lt;x/" />', $file->body);
    }

    public function testDefaultDirectoryIsFrontendDist(): void
    {
        self::assertSame(realpath(self::FRONTEND), realpath(dirname(FrontendAssets::defaultDirectory())));
        self::assertSame('dist', basename(FrontendAssets::defaultDirectory()));
    }

    public function testOnlyBuiltFileTypes(): void
    {
        self::assertSame(['js', 'css', 'woff2', 'woff'], array_keys(FrontendAssets::CONTENT_TYPES));
    }
}
