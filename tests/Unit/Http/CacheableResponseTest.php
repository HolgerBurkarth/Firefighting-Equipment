<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\CacheableResponse;
use FirefightingEquipment\Http\CachePolicy;
use FirefightingEquipment\Http\ContentEncoding;
use FirefightingEquipment\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheableResponse::class)]
#[CoversClass(CachePolicy::class)]
#[UsesClass(ContentEncoding::class)]
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
        yield 'JSON ohne charset' => ['application/json', self::ETAG];
        yield 'Problem-JSON' => ['application/problem+json', self::ETAG];
        yield 'CSV' => ['text/csv; charset=utf-8', self::ETAG];
        yield 'HTML ohne charset' => ['text/html', self::ETAG];
        yield 'Text' => ['text/plain; charset=utf-8', self::ETAG];
        yield 'SVG' => ['image/svg+xml', self::ETAG];
        yield 'ETag ohne Anführungszeichen' => [CacheableResponse::PNG, 'abc'];
        yield 'ETag mit Zeilenumbruch' => [CacheableResponse::PNG, "\"a\nb\""];
    }

    public function testFrontendContentTypes(): void
    {
        foreach (['text/html; charset=utf-8', 'text/javascript; charset=utf-8', 'text/css; charset=utf-8', 'font/woff2', 'font/woff', 'application/wasm', 'application/json; charset=utf-8'] as $type) {
            $response = new CacheableResponse(new Request('GET', '/x'), $type, 'x', self::ETAG, CachePolicy::Revalidate);
            self::assertSame($type, $response->headers()['Content-Type']);
        }
    }

    public function testSecurityHeadersAlsoOnNotModified(): void
    {
        $security = ['Content-Security-Policy' => "default-src 'self'", 'Referrer-Policy' => 'same-origin', 'X-Frame-Options' => 'DENY'];
        foreach (['', self::ETAG] as $ifNoneMatch) {
            $request = new Request('GET', '/x', $ifNoneMatch === '' ? [] : ['if-none-match' => $ifNoneMatch]);
            $headers = new CacheableResponse($request, CacheableResponse::HTML, '<p>', self::ETAG, CachePolicy::Revalidate, $security)->headers();

            self::assertSame($security, array_intersect_key($headers, $security));
            self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        }
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function rejectedSecurityHeaders(): iterable
    {
        yield 'Cache-Control' => [['Cache-Control' => 'public']];
        yield 'Set-Cookie' => [['Set-Cookie' => 'a=b']];
        yield 'Zeilenumbruch' => [['Referrer-Policy' => "same-origin\r\nSet-Cookie: a=b"]];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('rejectedSecurityHeaders')]
    public function testOnlyAllowedSecurityHeaders(array $headers): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheableResponse(new Request('GET', '/x'), CacheableResponse::HTML, 'x', self::ETAG, CachePolicy::Revalidate, $headers);
    }

    public function testCompressedVariant(): void
    {
        $response = new CacheableResponse(new Request('GET', '/x'), CacheableResponse::JAVASCRIPT, 'BR', self::ETAG, CachePolicy::Immutable, contentEncoding: ContentEncoding::Brotli, varyAcceptEncoding: true);

        self::assertSame([
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Content-Encoding' => 'br',
            'Content-Length' => '2',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => self::ETAG,
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Accept-Encoding',
        ], $response->headers());
        self::assertSame('BR', $response->body());
    }

    public function testUncompressedWithVariantsVaries(): void
    {
        $headers = new CacheableResponse(new Request('GET', '/x'), CacheableResponse::CSS, 'x', self::ETAG, CachePolicy::Immutable, varyAcceptEncoding: true)->headers();

        self::assertSame('Accept-Encoding', $headers['Vary']);
        self::assertArrayNotHasKey('Content-Encoding', $headers);
    }

    public function testNotModifiedKeepsVaryWithoutContentEncoding(): void
    {
        $request = new Request('GET', '/x', ['if-none-match' => self::ETAG]);
        $response = new CacheableResponse($request, CacheableResponse::JAVASCRIPT, 'GZ', self::ETAG, CachePolicy::Revalidate, contentEncoding: ContentEncoding::Gzip, varyAcceptEncoding: true);

        self::assertSame(304, $response->statusCode());
        self::assertSame('Accept-Encoding', $response->headers()['Vary']);
        self::assertArrayNotHasKey('Content-Encoding', $response->headers());
        self::assertArrayNotHasKey('Content-Length', $response->headers());
        self::assertSame('', $response->body());
    }

    public function testWithoutVariantsNoVary(): void
    {
        self::assertArrayNotHasKey('Vary', new CacheableResponse(new Request('GET', '/x'), CacheableResponse::PNG, 'x', self::ETAG, CachePolicy::Immutable)->headers());
    }

    public function testContentEncodingRequiresVary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheableResponse(new Request('GET', '/x'), CacheableResponse::JAVASCRIPT, 'x', self::ETAG, CachePolicy::Immutable, contentEncoding: ContentEncoding::Gzip);
    }

    #[DataProvider('rejectedArguments')]
    public function testOnlyAllowedContentTypesAndEtags(string $contentType, string $etag): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheableResponse(new Request('GET', '/x'), $contentType, 'x', $etag, CachePolicy::Revalidate);
    }
}
