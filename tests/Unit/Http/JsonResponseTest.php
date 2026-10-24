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
}
