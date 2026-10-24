<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\AppPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppPath::class)]
final class AppPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function validPaths(): iterable
    {
        yield 'service health' => ['/firefighting/service/api/v1/health', 'service', 'api/v1/health'];
        yield 'Stadt health' => ['/firefighting/immendingen/api/v1/health', 'immendingen', 'api/v1/health'];
        yield 'mit Query-String' => ['/firefighting/immendingen/api/v1/tenant?x=1&y=/a', 'immendingen', 'api/v1/tenant'];
        yield 'mit Fragment' => ['/firefighting/immendingen/api/v1/tenant#oben', 'immendingen', 'api/v1/tenant'];
        yield 'ein Slash am Ende' => ['/firefighting/immendingen/api/v1/health/', 'immendingen', 'api/v1/health'];
        yield 'nur Bereich' => ['/firefighting/immendingen', 'immendingen', ''];
        yield 'nur Bereich mit Slash' => ['/firefighting/immendingen/', 'immendingen', ''];
        yield 'Kürzel mit Ziffern und Bindestrich' => ['/firefighting/stadt-2/api', 'stadt-2', 'api'];
        yield 'Kürzel noch ungeprüft' => ['/firefighting/-a--b-/api', '-a--b-', 'api'];
        yield 'kodierte Zeichen bleiben' => ['/firefighting/stadt/api%2Fv1', 'stadt', 'api%2Fv1'];
    }

    #[DataProvider('validPaths')]
    public function testValidPathIsSplit(string $uri, string $scope, string $rest): void
    {
        $path = AppPath::parse($uri);

        self::assertNotNull($path);
        self::assertSame($scope, $path->scope);
        self::assertSame($rest, $path->rest);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'leer' => [''];
        yield 'Wurzel' => ['/'];
        yield 'nur Präfix' => ['/firefighting'];
        yield 'nur Präfix mit Slash' => ['/firefighting/'];
        yield 'Präfix ohne Trenner' => ['/firefightingx/stadt/api'];
        yield 'anderes Präfix' => ['/feuerwehr/stadt/api'];
        yield 'ohne führenden Slash' => ['firefighting/stadt/api'];
        yield 'Präfix großgeschrieben' => ['/Firefighting/stadt/api'];
        yield 'Kürzel mit Großbuchstaben' => ['/firefighting/Stadt/api'];
        yield 'Kürzel mit Umlaut' => ['/firefighting/m%C3%BCnchen/api'];
        yield 'Kürzel mit Punkt' => ['/firefighting/../api'];
        yield 'leeres Kürzel' => ['/firefighting//api'];
        yield 'doppelter Slash im Pfad' => ['/firefighting/stadt/api//v1'];
        yield 'zwei Slashes am Ende' => ['/firefighting/stadt/api//'];
        yield 'Query-String allein' => ['?/firefighting/stadt/api'];
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathIsRejected(string $uri): void
    {
        self::assertNull(AppPath::parse($uri));
    }

    public function testServiceScopeIsRecognised(): void
    {
        self::assertTrue(AppPath::parse('/firefighting/service/api/v1/health')?->isService());
        self::assertFalse(AppPath::parse('/firefighting/service-stadt/api/v1/health')?->isService());
    }
}
