<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Api\V1\HealthHandler;
use FirefightingEquipment\Api\V1\TenantHandler;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Database\LazyConnection;
use FirefightingEquipment\Http\Access;
use FirefightingEquipment\Http\AllowedOrigins;
use FirefightingEquipment\Http\AppPath;
use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestGuard;
use FirefightingEquipment\Http\RequestHandler;
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
 * Anwendung ohne Datenbank: Die Verbindung schlägt immer fehl, gezählt wird jeder Versuch.
 * Erfolgreiche Anfragen an Städte prüft tests/Integration/Http/ApplicationTest.php.
 */
#[CoversClass(Application::class)]
#[CoversClass(HealthHandler::class)]
#[CoversClass(TenantResolver::class)]
#[CoversClass(Routes::class)]
#[CoversClass(RequestGuard::class)]
#[UsesClass(Access::class)]
#[UsesClass(AllowedOrigins::class)]
#[UsesClass(AppPath::class)]
#[UsesClass(DatabaseError::class)]
#[UsesClass(ErrorReporter::class)]
#[UsesClass(HttpException::class)]
#[UsesClass(InvalidTenantValueException::class)]
#[UsesClass(JsonResponse::class)]
#[UsesClass(LazyConnection::class)]
#[UsesClass(Request::class)]
#[UsesClass(RequestContext::class)]
#[UsesClass(RouteMatch::class)]
#[UsesClass(Router::class)]
#[UsesClass(TenantHandler::class)]
#[UsesClass(TenantSlug::class)]
#[UsesClass(PasswordHasher::class)]
final class ApplicationTest extends TestCase
{
    private int $connectAttempts = 0;

    /** @var list<string> */
    private array $log = [];

    public function testCreateUsesDefaultArgon2idParameters(): void
    {
        // create() lädt die Konfiguration erst bei Bedarf; Hash-Parameter liest es nie daraus.
        $hasher = new \ReflectionProperty(Application::class, 'hasher')->getValue(Application::create('gibt-es-nicht.php'));

        self::assertInstanceOf(PasswordHasher::class, $hasher);
        self::assertFalse($hasher->needsRehash(PasswordHasher::DEFAULT_DUMMY_HASH));
        self::assertSame(PasswordHasher::DEFAULT_DUMMY_HASH, $hasher->dummyHash());
    }

    public function testServiceHealthWithBrokenDatabaseIs503InStatusFormat(): void
    {
        $response = $this->handle('GET', '/firefighting/service/api/v1/health');

        self::assertSame(503, $response->statusCode());
        self::assertSame('{"status":"error","database":"error"}', $response->body());
        self::assertSame(1, $this->connectAttempts);
        self::assertCount(1, $this->log);
        self::assertStringContainsString('firefighting: health: ' . ConnectionException::class, $this->log[0]);
    }

    public function testTenantRouteWithBrokenDatabaseIs503DatabaseUnavailable(): void
    {
        $response = $this->handle('GET', '/firefighting/immendingen/api/v1/health');

        self::assertSame(503, $response->statusCode());
        self::assertSame(
            '{"error":"database_unavailable","message":"Die Datenbank ist zurzeit nicht erreichbar."}',
            $response->body(),
        );
        self::assertCount(1, $this->log);
        self::assertStringContainsString('firefighting: database_unavailable: ', $this->log[0]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notFoundWithoutDatabase(): iterable
    {
        yield 'ohne Präfix' => ['GET', '/api/v1/health'];
        yield 'nur Präfix' => ['GET', '/firefighting/'];
        yield 'Stadt ohne Pfad' => ['GET', '/firefighting/immendingen/'];
        yield 'unbekannter Pfad unter Stadt' => ['GET', '/firefighting/immendingen/api/v1/unbekannt'];
        yield 'unbekannter Pfad unter service' => ['GET', '/firefighting/service/api/v1/unbekannt'];
        yield 'tenant unter service' => ['GET', '/firefighting/service/api/v1/tenant'];
        yield 'ungültiges Kürzel' => ['GET', '/firefighting/-stadt/api/v1/tenant'];
        yield 'Kürzel zu lang' => ['GET', '/firefighting/' . str_repeat('a', 51) . '/api/v1/tenant'];
        yield 'Großbuchstaben' => ['GET', '/firefighting/Immendingen/api/v1/tenant'];
    }

    #[DataProvider('notFoundWithoutDatabase')]
    public function testNotFoundNeedsNoDatabase(string $method, string $uri): void
    {
        $response = $this->handle($method, $uri);

        self::assertSame(404, $response->statusCode());
        self::assertSame('{"error":"not_found","message":"Die Adresse wurde nicht gefunden."}', $response->body());
        self::assertSame(0, $this->connectAttempts);
        self::assertSame([], $this->log);
    }

    public function testWrongMethodIs405WithAllowAndNeedsNoDatabase(): void
    {
        $response = $this->handle('POST', '/firefighting/immendingen/api/v1/tenant');

        self::assertSame(405, $response->statusCode());
        self::assertSame('GET', $response->headers()['Allow']);
        self::assertSame('method_not_allowed', json_decode($response->body(), true)['error']);
        self::assertSame(0, $this->connectAttempts);
    }

    public function testServiceLoginWithBrokenDatabaseIs503(): void
    {
        $request = new Request(
            'POST',
            '/firefighting/service/api/v1/auth/login',
            ['content-type' => 'application/json'],
            body: '{"email":"chef@example.org","password":"geheim-geheim"}',
        );

        $response = $this->application(Routes::service(), Routes::tenant())->handle($request);

        self::assertSame(503, $response->statusCode());
        self::assertSame('database_unavailable', json_decode($response->body(), true)['error']);
        self::assertStringNotContainsString('geheim', implode("\n", $this->log));
    }

    public function testUnexpectedErrorIs500WithGenericMessage(): void
    {
        $handler = new class implements RequestHandler {
            public function handle(RequestContext $context): JsonResponse
            {
                throw new \RuntimeException("Access denied for user 'geheim'@'db.example' in /pfad/datei.php");
            }
        };
        $app = $this->application(Router::forService()->add('GET', 'boom', Access::Public, $handler), Router::forTenant());

        $response = $app->handle(new Request('GET', '/firefighting/service/boom'));

        self::assertSame(500, $response->statusCode());
        self::assertSame('{"error":"internal_error","message":"Interner Fehler."}', $response->body());
        self::assertCount(1, $this->log);
        self::assertStringContainsString('firefighting: internal_error: RuntimeException:', $this->log[0]);
        self::assertStringNotContainsString('geheim', $this->log[0]);
        self::assertStringNotContainsString('db.example', $this->log[0]);
    }

    public function testUnencodableResponseIs500(): void
    {
        $handler = new class implements RequestHandler {
            public function handle(RequestContext $context): JsonResponse
            {
                return new JsonResponse(['kaputt' => "\xB1\x31"]);
            }
        };
        $app = $this->application(Router::forService()->add('GET', 'kaputt', Access::Public, $handler), Router::forTenant());

        $response = $app->handle(new Request('GET', '/firefighting/service/kaputt'));

        self::assertSame(500, $response->statusCode());
        self::assertStringContainsString('JsonException', $this->log[0]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allResponses(): iterable
    {
        yield '404' => ['GET', '/x'];
        yield '405' => ['POST', '/firefighting/service/api/v1/health'];
        yield '503 Status-Format' => ['GET', '/firefighting/service/api/v1/health'];
        yield '503 Fehlerformat' => ['GET', '/firefighting/immendingen/api/v1/tenant'];
    }

    #[DataProvider('allResponses')]
    public function testEveryResponseHasSecurityAndCacheHeaders(string $method, string $uri): void
    {
        $headers = $this->handle($method, $uri)->headers();

        self::assertSame('no-store', $headers['Cache-Control']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('application/json; charset=utf-8', $headers['Content-Type']);
    }

    /**
     * @return iterable<string, array{Request, int, string}>
     */
    public static function rejectedMutatingRequests(): iterable
    {
        $json = ['content-type' => 'application/json'];

        yield 'ohne Content-Type' => [self::post('/firefighting/service/echo/1', [], '{}'), 415, 'unsupported_media_type'];
        yield 'Formular' => [
            self::post('/firefighting/immendingen/echo/1', ['content-type' => 'application/x-www-form-urlencoded'], 'a=1'),
            415,
            'unsupported_media_type',
        ];
        yield 'text/plain' => [self::post('/firefighting/service/echo/1', ['content-type' => 'text/plain'], '{}'), 415, 'unsupported_media_type'];
        yield 'fremder Origin' => [
            self::post('/firefighting/immendingen/echo/1', [...$json, 'origin' => 'https://evil.example'], '{}'),
            403,
            'origin_not_allowed',
        ];
        yield 'Origin null' => [self::post('/firefighting/service/echo/1', [...$json, 'origin' => 'null'], '{}'), 403, 'origin_not_allowed'];
        yield 'leerer Body' => [self::post('/firefighting/service/echo/1', $json, ''), 400, 'invalid_request'];
        yield 'Body zu groß' => [
            new Request('POST', '/firefighting/service/echo/1', $json, bodyTooLarge: true),
            400,
            'invalid_request',
        ];
        yield 'kaputtes JSON' => [self::post('/firefighting/service/echo/1', $json, '{"a":'), 400, 'invalid_request'];
        yield 'JSON-Liste' => [self::post('/firefighting/service/echo/1', $json, '[]'), 400, 'invalid_request'];
        yield 'DELETE ohne Content-Type' => [
            new Request('DELETE', '/firefighting/service/echo/1', [], body: '{}'),
            415,
            'unsupported_media_type',
        ];
    }

    #[DataProvider('rejectedMutatingRequests')]
    public function testMutatingRequestIsRejectedBeforeDatabase(Request $request, int $status, string $code): void
    {
        $response = $this->echoApplication()->handle($request);

        self::assertSame($status, $response->statusCode());
        self::assertSame($code, json_decode($response->body(), true)['error']);
        self::assertSame(0, $this->connectAttempts);
    }

    public function testValidMutatingRequestPassesBodyAndParamsToHandler(): void
    {
        $request = self::post(
            '/firefighting/service/echo/12',
            ['content-type' => 'Application/JSON; Charset=UTF-8', 'origin' => 'https://www.prodad.de'],
            ' {"name":"Süd","n":3}',
        );

        $response = $this->echoApplication()->handle($request);

        self::assertSame(200, $response->statusCode());
        self::assertSame('{"params":{"id":12},"body":{"name":"Süd","n":3}}', $response->body());
    }

    public function testEmptyJsonObjectIsAccepted(): void
    {
        $response = $this->echoApplication()->handle(self::post('/firefighting/service/echo/1', ['content-type' => 'application/json'], '{}'));

        self::assertSame('{"params":{"id":1},"body":[]}', $response->body());
    }

    public function testConfiguredOriginIsAllowedAndLoadedOnlyWhenNeeded(): void
    {
        $loads = 0;
        $origins = new AllowedOrigins(static function () use (&$loads): array {
            $loads++;

            return ['http://localhost:8000'];
        });
        $app = $this->echoApplication(new RequestGuard($origins));
        $json = ['content-type' => 'application/json'];

        self::assertSame(200, $app->handle(self::post('/firefighting/service/echo/1', $json, '{}'))->statusCode());
        self::assertSame(200, $app->handle(self::post('/firefighting/service/echo/1', [...$json, 'origin' => 'https://www.prodad.de'], '{}'))->statusCode());
        self::assertSame(0, $loads);

        self::assertSame(200, $app->handle(self::post('/firefighting/service/echo/1', [...$json, 'origin' => 'http://localhost:8000'], '{}'))->statusCode());
        self::assertSame(403, $app->handle(self::post('/firefighting/service/echo/1', [...$json, 'origin' => 'http://localhost:8001'], '{}'))->statusCode());
        self::assertSame(1, $loads);
    }

    public function testUnknownPathIs404EvenForInvalidMutatingRequest(): void
    {
        $response = $this->echoApplication()->handle(self::post('/firefighting/service/unbekannt', [], 'x'));

        self::assertSame(404, $response->statusCode());
    }

    /**
     * @param array<string, string> $headers
     */
    private static function post(string $uri, array $headers, string $body): Request
    {
        return new Request('POST', $uri, $headers, body: $body);
    }

    private function echoApplication(RequestGuard $guard = new RequestGuard()): Application
    {
        $echo = new class implements RequestHandler {
            public function handle(RequestContext $context): JsonResponse
            {
                return new JsonResponse(['params' => $context->params, 'body' => $context->body]);
            }
        };
        $service = Router::forService()
            ->add('POST', 'echo/{id}', Access::Public, $echo)
            ->add('DELETE', 'echo/{id}', Access::Public, $echo);
        $tenant = Router::forTenant()->add('POST', 'echo/{id}', Access::Public, $echo);

        return new Application($service, $tenant, $this->failingDb(), $this->errorReporter(), guard: $guard);
    }

    private function handle(string $method, string $uri): JsonResponse
    {
        return $this->application(Routes::service(), Routes::tenant())->handle(new Request($method, $uri));
    }

    private function application(Router $service, Router $tenant): Application
    {
        return new Application($service, $tenant, $this->failingDb(), $this->errorReporter());
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
