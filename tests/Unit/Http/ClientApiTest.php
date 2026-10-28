<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\ClientApi;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\RequestGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Gemeinsame Vertragsnummer mit dem Frontend (resources/client-api.json). */
#[CoversClass(ClientApi::class)]
final class ClientApiTest extends TestCase
{
    public function testMatchesSharedFile(): void
    {
        $shared = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/resources/client-api.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($shared);
        self::assertSame(ClientApi::CURRENT, $shared['client_api']);
        self::assertSame(ClientApi::HEADER, $shared['header']);
    }

    public function testHeaderDoesNotDisturbTheRequestGuard(): void
    {
        // Wie vom Browser: X-Client-Api kommt als HTTP_X_CLIENT_API und stört die CSRF-Prüfung nicht.
        $request = Request::fromServer(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/firefighting/test/api/v1/auth/logout',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'https://www.prodad.de',
                'HTTP_X_CLIENT_API' => '1',
            ],
            [],
            static function () {
                $stream = fopen('php://memory', 'r+b');
                fwrite($stream, '{}');
                rewind($stream);

                return $stream;
            },
        );

        new RequestGuard()->check($request);

        self::assertSame('1', $request->header(ClientApi::HEADER));
    }
}
