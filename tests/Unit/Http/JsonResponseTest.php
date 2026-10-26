<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\JsonResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonResponse::class)]
final class JsonResponseTest extends TestCase
{
    public function testDefaultStatusCodeIs200(): void
    {
        $response = new JsonResponse(['status' => 'ok']);

        self::assertSame(200, $response->statusCode());
    }

    public function testCustomStatusCodeIsKept(): void
    {
        $response = new JsonResponse(['error' => 'not_found'], 404);

        self::assertSame(404, $response->statusCode());
    }

    public function testBodyIsJson(): void
    {
        $response = new JsonResponse(['status' => 'ok']);

        self::assertSame('{"status":"ok"}', $response->body());
    }

    public function testUmlautsAndSlashesAreNotEscaped(): void
    {
        $response = new JsonResponse(['location' => 'Gerätehaus Süd/Lager']);

        self::assertSame('{"location":"Gerätehaus Süd/Lager"}', $response->body());
    }

    public function testInvalidUtf8ThrowsException(): void
    {
        $response = new JsonResponse(['broken' => "\xB1\x31"]);

        $this->expectException(\JsonException::class);
        $response->body();
    }

    public function testContentTypeIsJsonUtf8(): void
    {
        $response = new JsonResponse([]);

        self::assertSame('application/json; charset=utf-8', $response->contentType());
    }

    public function testEveryResponseHasSecurityAndCacheHeaders(): void
    {
        $response = new JsonResponse(['status' => 'ok']);

        self::assertSame([
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $response->headers());
    }

    public function testAdditionalHeadersAreKept(): void
    {
        $response = new JsonResponse([], 405, ['Allow' => 'GET']);

        self::assertSame('GET', $response->headers()['Allow']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
    }

    public function testDefaultHeadersCannotBeOverridden(): void
    {
        $response = new JsonResponse([], 200, ['Cache-Control' => 'max-age=3600']);

        self::assertSame('no-store', $response->headers()['Cache-Control']);
    }

    public function testErrorUsesUniformFormat(): void
    {
        $response = JsonResponse::error('not_found', 'Die Adresse wurde nicht gefunden.', 404);

        self::assertSame(404, $response->statusCode());
        self::assertSame('{"error":"not_found","message":"Die Adresse wurde nicht gefunden."}', $response->body());
    }

    public function testErrorDetailsAreAlwaysAnObject(): void
    {
        $with = JsonResponse::error('rule_violation', 'x', 409, details: ['devices' => 2]);
        $empty = JsonResponse::error('rule_violation', 'x', 409, details: []);

        self::assertSame('{"error":"rule_violation","message":"x","details":{"devices":2}}', $with->body());
        self::assertSame('{"error":"rule_violation","message":"x","details":{}}', $empty->body());
    }
}
