<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\ClientApi;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\RequestGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testMinimumIsAtMostCurrent(): void
    {
        self::assertGreaterThanOrEqual(1, ClientApi::MIN);
        self::assertLessThanOrEqual(ClientApi::CURRENT, ClientApi::MIN);
    }

    /**
     * @return iterable<string, array{string|null, int|null}>
     */
    public static function headerValues(): iterable
    {
        yield 'ohne Header' => [null, null];
        yield 'leer' => ['', null];
        yield 'null' => ['0', 0];
        yield 'eins' => ['1', 1];
        yield 'zweistellig' => ['12', 12];
        yield 'neun Stellen' => ['999999999', 999_999_999];
        yield 'zehn Stellen' => ['1000000000', null];
        yield 'führende Null' => ['01', null];
        yield 'Vorzeichen' => ['-1', null];
        yield 'Plus' => ['+1', null];
        yield 'Leerzeichen' => [' 1', null];
        yield 'Dezimalzahl' => ['1.0', null];
        yield 'Text' => ['alt', null];
        yield 'Zeilenumbruch am Ende' => ["1\n", null];
    }

    #[DataProvider('headerValues')]
    public function testFromHeaderAcceptsOnlyPlainNumbers(?string $value, ?int $expected): void
    {
        self::assertSame($expected, ClientApi::fromHeader($value));
    }

    public function testAppsBeforeRightsAreOutdated(): void
    {
        // Schritt 1.8a: Apps vor 1.7c (X-Client-Api 1) richten die Oberfläche nach `role`.
        self::assertSame(2, ClientApi::MIN);
        self::assertTrue(ClientApi::isOutdated('1'));
        self::assertFalse(ClientApi::isOutdated('2'));
        // Apps vor Phase 0 senden keinen Header und bleiben unberührt (auch curl, Deploy-Skript).
        self::assertFalse(ClientApi::isOutdated(null));
    }

    public function testOutdatedOnlyBelowMinimum(): void
    {
        self::assertTrue(ClientApi::isOutdated('0'));
        self::assertFalse(ClientApi::isOutdated((string) ClientApi::MIN));
        self::assertFalse(ClientApi::isOutdated((string) (ClientApi::CURRENT + 1)));
        // Ohne Header oder ohne Nummer gilt die Anfrage als aktuell.
        self::assertFalse(ClientApi::isOutdated(null));
        self::assertFalse(ClientApi::isOutdated('alt'));

        self::assertTrue(ClientApi::isOutdated('2', 3));
        self::assertFalse(ClientApi::isOutdated('3', 3));
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
