<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\CacheableResponse;
use FirefightingEquipment\Http\CachePolicy;
use FirefightingEquipment\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheableResponse::class)]
#[CoversClass(CachePolicy::class)]
#[UsesClass(Request::class)]
final class CacheableResponseTest extends TestCase
{
    private const string ETAG = '"0123456789abcdef-512"';

    public function testFullResponseHeaders(): void
    {
        $response = new CacheableResponse(new Request('GET', '/x'), CacheableResponse::PNG, 'PNGDATA', self::ETAG, CachePolicy::Immutable);

        self::assertSame(200, $response->statusCode());
        self::assertSame([
            'Content-Type' => 'image/png',
            'Content-Length' => '7',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => self::ETAG,
            'X-Content-Type-Options' => 'nosniff',
        ], $response->headers());
        self::assertSame('PNGDATA', $response->body());
    }

    public function testRevalidatePolicy(): void
    {
        $response = new CacheableResponse(new Request('GET', '/x'), CacheableResponse::MANIFEST, '{}', self::ETAG, CachePolicy::Revalidate);

        self::assertSame('no-cache', $response->headers()['Cache-Control']);
        self::assertSame('application/manifest+json; charset=utf-8', $response->headers()['Content-Type']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ifNoneMatch(): iterable
    {
        yield 'gleich' => [self::ETAG, true];
        yield 'schwach' => ['W/' . self::ETAG, true];
        yield 'Liste' => ['"anders", ' . self::ETAG, true];
        yield 'Stern' => ['*', true];
        yield 'anders' => ['"0123456789abcdef-192"', false];
        yield 'ohne Anführungszeichen' => ['0123456789abcdef-512', false];
        yield 'leer' => ['', false];
    }

    #[DataProvider('ifNoneMatch')]
    public function testConditionalRequest(string $header, bool $notModified): void
    {
        $request = new Request('GET', '/x', ['if-none-match' => $header]);
        $response = new CacheableResponse($request, CacheableResponse::PNG, 'PNGDATA', self::ETAG, CachePolicy::Immutable);

        self::assertSame($notModified ? 304 : 200, $response->statusCode());
        self::assertSame($notModified ? '' : 'PNGDATA', $response->body());
        self::assertSame(self::ETAG, $response->headers()['ETag']);
        self::assertSame('public, max-age=31536000, immutable', $response->headers()['Cache-Control']);
        self::assertSame($notModified, !isset($response->headers()['Content-Type']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedArguments(): iterable
    {
        yield 'JSON' => ['application/json; charset=utf-8', self::ETAG];
        yield 'CSV' => ['text/csv; charset=utf-8', self::ETAG];
        yield 'HTML' => ['text/html', self::ETAG];
        yield 'ETag ohne Anführungszeichen' => [CacheableResponse::PNG, 'abc'];
        yield 'ETag mit Zeilenumbruch' => [CacheableResponse::PNG, "\"a\nb\""];
    }

    #[DataProvider('rejectedArguments')]
    public function testOnlyAllowedContentTypesAndEtags(string $contentType, string $etag): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheableResponse(new Request('GET', '/x'), $contentType, 'x', $etag, CachePolicy::Revalidate);
    }
}
