<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http\Frontend;

use FirefightingEquipment\Api\V1\HealthHandler;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Database\LazyConnection;
use FirefightingEquipment\Http\Access;
use FirefightingEquipment\Http\AppPath;
use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\CacheableResponse;
use FirefightingEquipment\Http\CachePolicy;
use FirefightingEquipment\Http\ContentEncoding;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Http\Frontend\ContentSecurityPolicy;
use FirefightingEquipment\Http\Frontend\FrontendAssets;
use FirefightingEquipment\Http\Frontend\FrontendFile;
use FirefightingEquipment\Http\Frontend\FrontendHandler;
use FirefightingEquipment\Http\Frontend\FrontendUnavailableException;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestGuard;
use FirefightingEquipment\Http\RouteMatch;
use FirefightingEquipment\Http\Router;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Http\TenantResolver;
use FirefightingEquipment\Tenant\InvalidTenantValueException;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\User\Password\PasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Auslieferung des Frontends über die ganze Anwendung (echte Routen), mit einem kleinen Test-Build
 * (tests/Fixtures/frontend-dist) und einer Datenbank, die immer fehlschlägt: Das Frontend darf sie
 * nie brauchen.
 */
#[CoversClass(Application::class)]
#[CoversClass(FrontendHandler::class)]
#[CoversClass(FrontendAssets::class)]
#[CoversClass(FrontendFile::class)]
#[CoversClass(FrontendUnavailableException::class)]
#[CoversClass(ContentSecurityPolicy::class)]
#[CoversClass(CacheableResponse::class)]
#[UsesClass(Access::class)]
#[UsesClass(AppPath::class)]
#[UsesClass(CachePolicy::class)]
#[UsesClass(ContentEncoding::class)]
#[UsesClass(DatabaseError::class)]
#[UsesClass(ErrorReporter::class)]
#[UsesClass(HealthHandler::class)]
#[UsesClass(HttpException::class)]
#[UsesClass(InvalidTenantValueException::class)]
#[UsesClass(JsonResponse::class)]
#[UsesClass(LazyConnection::class)]
#[UsesClass(PasswordHasher::class)]
#[UsesClass(Request::class)]
#[UsesClass(RequestContext::class)]
#[UsesClass(RequestGuard::class)]
#[UsesClass(RouteMatch::class)]
#[UsesClass(Router::class)]
#[UsesClass(Routes::class)]
#[UsesClass(TenantResolver::class)]
#[UsesClass(TenantSlug::class)]
final class FrontendDeliveryTest extends TestCase
{
    private const string DIST = __DIR__ . '/../../../Fixtures/frontend-dist';

    private int $connectAttempts = 0;

    /** @var list<string> */
    private array $log = [];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function appPages(): iterable
    {
        yield 'Startseite Stadt' => ['/firefighting/immendingen/', '/firefighting/immendingen/'];
        yield 'Startseite ohne Slash' => ['/firefighting/immendingen', '/firefighting/immendingen/'];
        yield 'Deep-Link' => ['/firefighting/immendingen/geraete/12', '/firefighting/immendingen/'];
        yield 'Mail-Link' => ['/firefighting/test/passwort-zuruecksetzen', '/firefighting/test/'];
        yield 'mit Query-String' => ['/firefighting/test/anmelden?weiter=1', '/firefighting/test/'];
        yield 'index.html' => ['/firefighting/test/index.html', '/firefighting/test/'];
        yield 'Super-Admin' => ['/firefighting/service/', '/firefighting/service/'];
        yield 'Super-Admin Deep-Link' => ['/firefighting/service/staedte/3', '/firefighting/service/'];
        yield 'unbekannte Stadt (prüft die App)' => ['/firefighting/gibt-es-nicht/', '/firefighting/gibt-es-nicht/'];
    }

    #[DataProvider('appPages')]
    public function testAppPageWithBasePathAndSecurityHeaders(string $uri, string $basePath): void
    {
        $response = $this->get($uri);

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString(sprintf('<base href="%s" />', $basePath), $response->body());
        self::assertStringNotContainsString(FrontendAssets::BASE_PLACEHOLDER, $response->body());
        $headers = $response->headers();
        self::assertSame('text/html; charset=utf-8', $headers['Content-Type']);
        self::assertSame('no-cache', $headers['Cache-Control']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame(ContentSecurityPolicy::header(), $headers['Content-Security-Policy']);
        self::assertSame('same-origin', $headers['Referrer-Policy']);
        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertArrayNotHasKey('Set-Cookie', $headers);
        self::assertSame(0, $this->connectAttempts);
        self::assertSame([], $this->log);
    }

    public function testAppPageIgnoresSessionCookie(): void
    {
        $response = $this->app()->handle(new Request('GET', '/firefighting/test/', cookies: ['ff_session' => 'kaputt']));

        self::assertSame(200, $response->statusCode());
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
        self::assertSame(0, $this->connectAttempts);
    }

    public function testAppPageDiffersOnlyInBasePath(): void
    {
        $a = $this->get('/firefighting/aa/')->body();
        $b = $this->get('/firefighting/bb/')->body();

        self::assertSame(str_replace('/firefighting/aa/', '/firefighting/bb/', $a), $b);
        self::assertNotSame($this->get('/firefighting/aa/')->headers()['ETag'], $this->get('/firefighting/bb/')->headers()['ETag']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function files(): iterable
    {
        yield 'Skript' => ['/firefighting/test/assets/index-abc123.js', 'text/javascript; charset=utf-8', 'public, max-age=31536000, immutable'];
        yield 'Stil' => ['/firefighting/test/assets/index-abc123.css', 'text/css; charset=utf-8', 'public, max-age=31536000, immutable'];
        yield 'Schrift' => ['/firefighting/immendingen/assets/font-abc123.woff2', 'font/woff2', 'public, max-age=31536000, immutable'];
        yield 'WebAssembly (Scanner)' => ['/firefighting/test/assets/zxing_reader-abc123.wasm', 'application/wasm', 'public, max-age=31536000, immutable'];
        yield 'Service Worker' => ['/firefighting/test/sw.js', 'text/javascript; charset=utf-8', 'no-cache'];
        yield 'Workbox' => ['/firefighting/service/workbox-abc123.js', 'text/javascript; charset=utf-8', 'no-cache'];
        yield 'Build-Kennung' => ['/firefighting/test/build.json', 'application/json; charset=utf-8', 'no-cache'];
        yield 'Build-Kennung (service)' => ['/firefighting/service/build.json', 'application/json; charset=utf-8', 'no-cache'];
    }

    #[DataProvider('files')]
    public function testBuildFiles(string $uri, string $contentType, string $cacheControl): void
    {
        $response = $this->get($uri);

        self::assertSame(200, $response->statusCode());
        $headers = $response->headers();
        self::assertSame($contentType, $headers['Content-Type']);
        self::assertSame($cacheControl, $headers['Cache-Control']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertArrayNotHasKey('Content-Security-Policy', $headers);
        $relative = (string) preg_replace('#^/firefighting/[a-z0-9-]+/#', '', $uri);
        self::assertSame(file_get_contents(self::DIST . '/' . $relative), $response->body());
        self::assertSame(0, $this->connectAttempts);
    }

    public function testNotModifiedWithMatchingEtag(): void
    {
        foreach (['/firefighting/test/', '/firefighting/test/assets/index-abc123.js'] as $uri) {
            $etag = $this->get($uri)->headers()['ETag'];
            $response = $this->app()->handle(new Request('GET', $uri, ['if-none-match' => $etag]));

            self::assertSame(304, $response->statusCode(), $uri);
            self::assertInstanceOf(CacheableResponse::class, $response);
            self::assertSame('', $response->body());
        }
    }

    /**
     * Fassungen im Test-Build: index-abc123.js mit .br und .gz, sw.js nur mit .gz (Inhalt beliebig,
     * PHP dekomprimiert nie).
     *
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function compressedFiles(): iterable
    {
        yield 'Chrome: Brotli' => ['assets/index-abc123.js', 'gzip, deflate, br, zstd', 'br'];
        yield 'nur gzip' => ['assets/index-abc123.js', 'gzip', 'gzip'];
        yield 'Brotli ausgeschlossen' => ['assets/index-abc123.js', 'br;q=0, gzip', 'gzip'];
        yield 'nichts Passendes' => ['assets/index-abc123.js', 'deflate', null];
        yield 'ohne Header' => ['assets/index-abc123.js', '', null];
        yield 'nur gzip vorhanden' => ['sw.js', 'gzip, deflate, br', 'gzip'];
        yield 'nur gzip vorhanden, Brotli verlangt' => ['sw.js', 'br', null];
    }

    #[DataProvider('compressedFiles')]
    public function testCompressedVariantByAcceptEncoding(string $file, string $acceptEncoding, ?string $encoding): void
    {
        $headers = $acceptEncoding === '' ? [] : ['accept-encoding' => $acceptEncoding];
        $response = $this->app()->handle(new Request('GET', '/firefighting/test/' . $file, $headers));

        self::assertInstanceOf(CacheableResponse::class, $response);
        self::assertSame(200, $response->statusCode());
        $suffix = match ($encoding) {
            'br' => '.br',
            'gzip' => '.gz',
            default => '',
        };
        self::assertSame(file_get_contents(self::DIST . '/' . $file . $suffix), $response->body());
        $headers = $response->headers();
        self::assertSame('text/javascript; charset=utf-8', $headers['Content-Type']);
        self::assertSame($encoding, $headers['Content-Encoding'] ?? null);
        self::assertSame((string) strlen($response->body()), $headers['Content-Length']);
        self::assertSame('Accept-Encoding', $headers['Vary']);
        $etagSuffix = $encoding === null ? '' : '-' . substr($suffix, 1);
        self::assertSame('"' . substr(hash('sha256', $response->body()), 0, 32) . $etagSuffix . '"', $headers['ETag']);
        self::assertSame(0, $this->connectAttempts);
    }

    public function testWithoutVariantsNoVary(): void
    {
        foreach (['/firefighting/test/', '/firefighting/test/assets/index-abc123.css', '/firefighting/test/build.json'] as $uri) {
            $headers = $this->app()->handle(new Request('GET', $uri, ['accept-encoding' => 'gzip, br']))->headers();

            self::assertArrayNotHasKey('Vary', $headers, $uri);
            self::assertArrayNotHasKey('Content-Encoding', $headers, $uri);
        }
    }

    public function testEtagPerVariant(): void
    {
        $uri = '/firefighting/test/assets/index-abc123.js';
        $etags = [];
        foreach (['br', 'gzip', 'identity'] as $acceptEncoding) {
            $etags[$acceptEncoding] = $this->app()->handle(new Request('GET', $uri, ['accept-encoding' => $acceptEncoding]))->headers()['ETag'];
        }
        self::assertCount(3, array_unique($etags));

        // 304 nur für die Fassung, die der Browser hat; Vary bleibt, Content-Encoding fehlt.
        $same = $this->app()->handle(new Request('GET', $uri, ['accept-encoding' => 'br', 'if-none-match' => $etags['br']]));
        self::assertSame(304, $same->statusCode());
        self::assertSame('Accept-Encoding', $same->headers()['Vary']);
        self::assertArrayNotHasKey('Content-Encoding', $same->headers());

        $other = $this->app()->handle(new Request('GET', $uri, ['accept-encoding' => 'gzip', 'if-none-match' => $etags['br']]));
        self::assertSame(200, $other->statusCode());
        self::assertSame('gzip', $other->headers()['Content-Encoding']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingOrForbiddenFiles(): iterable
    {
        yield 'Asset fehlt' => ['/firefighting/test/assets/index-alt999.js'];
        yield 'alter Workbox' => ['/firefighting/test/workbox-alt999.js'];
        yield 'Endung nicht erlaubt (assets)' => ['/firefighting/test/assets/notiz.txt'];
        yield 'Endung nicht erlaubt' => ['/firefighting/test/geheim.txt'];
        yield 'Favicon' => ['/firefighting/test/favicon.ico'];
        yield 'andere JSON-Datei' => ['/firefighting/test/geheim.json'];
        yield 'build.json in assets/' => ['/firefighting/test/assets/build.json'];
        yield 'build.json in Großschreibung' => ['/firefighting/test/BUILD.json'];
        yield 'Unterordner' => ['/firefighting/test/assets/sub/index-abc123.js'];
        yield 'Datei in App-Pfad' => ['/firefighting/test/geraete/index-abc123.js'];
        yield 'Punkte' => ['/firefighting/test/assets/../index.html'];
        yield 'Punkte zum Projekt' => ['/firefighting/test/assets/../../../composer.json'];
        yield 'kodierte Punkte' => ['/firefighting/test/assets/%2e%2e%2fsw.js'];
        yield 'kodierter Slash' => ['/firefighting/test/assets%2f..%2fsw.js'];
        yield 'Backslash' => ['/firefighting/test/assets/..\\sw.js'];
        yield 'versteckte Datei' => ['/firefighting/test/assets/.index-abc123.js'];
        yield 'versteckte Datei im Build-Ordner' => ['/firefighting/test/.htaccess'];
        yield 'NUL' => ["/firefighting/test/assets/index-abc123.js\0.css"];
        yield 'Brotli-Fassung direkt' => ['/firefighting/test/assets/index-abc123.js.br'];
        yield 'gzip-Fassung direkt' => ['/firefighting/test/sw.js.gz'];
        yield 'Fassung ohne Original' => ['/firefighting/test/assets/nur-komprimiert-abc123.js'];
        yield 'Fassung einer nicht erlaubten Datei' => ['/firefighting/test/assets/notiz.txt'];
        yield 'WebAssembly außerhalb von assets/' => ['/firefighting/test/zxing_reader-abc123.wasm'];
        yield 'WebAssembly in Großschreibung außerhalb von assets/' => ['/firefighting/test/zxing_reader-abc123.WASM'];
        yield 'ungültiges Kürzel' => ['/firefighting/-test/'];
        yield 'Kürzel zu lang' => ['/firefighting/' . str_repeat('a', 51) . '/'];
    }

    #[DataProvider('missingOrForbiddenFiles')]
    public function testMissingOrForbiddenFileIs404Json(string $uri): void
    {
        $response = $this->get($uri);

        self::assertSame(404, $response->statusCode(), $uri);
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('not_found', json_decode($response->body(), true)['error']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame(0, $this->connectAttempts);
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function unchangedRequests(): iterable
    {
        // Fallback nur bei GET: alle anderen Methoden auf Frontend-Pfaden wie bisher (keine Route: 404).
        foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'] as $method) {
            yield $method . ' App-Seite' => [$method, '/firefighting/test/', 404, 'not_found'];
            yield $method . ' Deep-Link' => [$method, '/firefighting/test/geraete/12', 404, 'not_found'];
            yield $method . ' Asset' => [$method, '/firefighting/test/assets/index-abc123.js', 404, 'not_found'];
        }
        // Unter api/ nie das Frontend.
        yield 'GET unbekannte API' => ['GET', '/firefighting/test/api/v1/unbekannt', 404, 'not_found'];
        yield 'GET api' => ['GET', '/firefighting/test/api', 404, 'not_found'];
        yield 'GET api/' => ['GET', '/firefighting/test/api/', 404, 'not_found'];
        yield 'GET api/v2' => ['GET', '/firefighting/service/api/v2/health', 404, 'not_found'];
        // Vorhandene Routen unverändert.
        yield 'POST auf GET-Route' => ['POST', '/firefighting/service/api/v1/health', 405, 'method_not_allowed'];
        yield 'HEAD auf GET-Route' => ['HEAD', '/firefighting/test/api/v1/tenant', 405, 'method_not_allowed'];
        yield 'POST Manifest' => ['POST', '/firefighting/test/manifest.webmanifest', 405, 'method_not_allowed'];
        yield 'ohne Präfix' => ['GET', '/index.html', 404, 'not_found'];
        yield 'nur Präfix' => ['GET', '/firefighting/', 404, 'not_found'];
    }

    #[DataProvider('unchangedRequests')]
    public function testOtherRequestsBehaveAsBefore(string $method, string $uri, int $status, string $code): void
    {
        $response = $this->app()->handle(new Request($method, $uri, ['content-type' => 'application/json'], body: '{}'));

        self::assertSame($status, $response->statusCode());
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame($code, json_decode($response->body(), true)['error']);
        self::assertSame(0, $this->connectAttempts);
    }

    public function testRoutesStillWin(): void
    {
        // Manifest und Health sind Routen und gehen weiter an PHP (hier: Datenbank nicht erreichbar).
        self::assertSame(503, $this->get('/firefighting/service/api/v1/health')->statusCode());
        self::assertSame('database_unavailable', json_decode($this->get('/firefighting/test/manifest.webmanifest')->body(), true)['error']);
        self::assertSame(2, $this->connectAttempts);
    }

    public function testWithoutFrontendUnknownPathsStay404(): void
    {
        $app = new Application(Routes::service(), Routes::tenant(), $this->failingDb(), $this->errorReporter());

        $response = $app->handle(new Request('GET', '/firefighting/test/'));

        self::assertSame(404, $response->statusCode());
        self::assertSame('not_found', json_decode($response->body(), true)['error']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenBuilds(): iterable
    {
        yield 'Build fehlt' => [__DIR__ . '/gibt-es-nicht'];
        yield 'ohne Platzhalter' => [__DIR__ . '/../../../Fixtures/frontend-dist-without-placeholder'];
    }

    #[DataProvider('brokenBuilds')]
    public function testMissingBuildIs503FrontendUnavailable(string $directory): void
    {
        $response = $this->app($directory)->handle(new Request('GET', '/firefighting/test/anmelden'));

        self::assertSame(503, $response->statusCode());
        self::assertSame(
            '{"error":"frontend_unavailable","message":"Die Oberfläche ist zurzeit nicht verfügbar."}',
            $response->body(),
        );
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertCount(1, $this->log);
        self::assertStringContainsString('firefighting: frontend_unavailable: ' . FrontendUnavailableException::class, $this->log[0]);
        self::assertStringNotContainsString($directory, $this->log[0]);
    }

    public function testMissingBuildStillServesApi(): void
    {
        $response = $this->app(__DIR__ . '/gibt-es-nicht')->handle(new Request('GET', '/firefighting/service/api/v1/health'));

        self::assertSame('{"status":"error","database":"error"}', $response instanceof JsonResponse ? $response->body() : '');
    }

    public function testCreateServesBuildFromFrontendDist(): void
    {
        $frontend = new \ReflectionProperty(Application::class, 'frontend')->getValue(Application::create('gibt-es-nicht.php'));
        self::assertInstanceOf(FrontendHandler::class, $frontend);

        $assets = new \ReflectionProperty(FrontendHandler::class, 'assets')->getValue($frontend);
        $directory = new \ReflectionProperty(FrontendAssets::class, 'directory')->getValue($assets);
        self::assertSame(realpath(__DIR__ . '/../../../..') . DIRECTORY_SEPARATOR . 'frontend', realpath(dirname($directory)));
        self::assertSame('dist', basename($directory));
    }

    private function get(string $uri): CacheableResponse|JsonResponse
    {
        $response = $this->app()->handle(new Request('GET', $uri));
        self::assertTrue($response instanceof CacheableResponse || $response instanceof JsonResponse);

        return $response;
    }

    private function app(string $directory = self::DIST): Application
    {
        return new Application(
            Routes::service(),
            Routes::tenant(),
            $this->failingDb(),
            $this->errorReporter(),
            frontend: new FrontendHandler(new FrontendAssets($directory)),
        );
    }

    private function failingDb(): LazyConnection
    {
        return new LazyConnection(function (): \PDO {
            $this->connectAttempts++;
            throw new ConnectionException('Verbindung zur Datenbank fehlgeschlagen (SQLSTATE HY000, Fehler 2002).');
        });
    }

    private function errorReporter(): ErrorReporter
    {
        return new ErrorReporter(function (string $line): void {
            $this->log[] = $line;
        });
    }
}
