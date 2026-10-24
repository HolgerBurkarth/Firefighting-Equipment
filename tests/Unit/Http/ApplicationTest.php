<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Api\V1\HealthHandler;
use FirefightingEquipment\Api\V1\TenantHandler;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Database\LazyConnection;
use FirefightingEquipment\Http\AppPath;
use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Http\Router;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Http\TenantResolver;
use FirefightingEquipment\Tenant\InvalidTenantValueException;
use FirefightingEquipment\Tenant\TenantSlug;
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
#[UsesClass(AppPath::class)]
#[UsesClass(DatabaseError::class)]
#[UsesClass(ErrorReporter::class)]
#[UsesClass(HttpException::class)]
#[UsesClass(InvalidTenantValueException::class)]
#[UsesClass(JsonResponse::class)]
#[UsesClass(LazyConnection::class)]
#[UsesClass(Request::class)]
#[UsesClass(RequestContext::class)]
#[UsesClass(Router::class)]
#[UsesClass(TenantHandler::class)]
#[UsesClass(TenantSlug::class)]
final class ApplicationTest extends TestCase
{
    private int $connectAttempts = 0;

    /** @var list<string> */
    private array $log = [];

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

    public function testUnexpectedErrorIs500WithGenericMessage(): void
    {
        $handler = new class implements RequestHandler {
            public function handle(RequestContext $context): JsonResponse
            {
                throw new \RuntimeException("Access denied for user 'geheim'@'db.example' in /pfad/datei.php");
            }
        };
        $app = $this->application(new Router()->add('GET', 'boom', $handler), new Router());

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
        $app = $this->application(new Router()->add('GET', 'kaputt', $handler), new Router());

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

    private function handle(string $method, string $uri): JsonResponse
    {
        return $this->application(Routes::service(), Routes::tenant())->handle(new Request($method, $uri));
    }

    private function application(Router $service, Router $tenant): Application
    {
        $db = new LazyConnection(function (): \PDO {
            $this->connectAttempts++;
            throw new ConnectionException('Verbindung zur Datenbank fehlgeschlagen (SQLSTATE HY000, Fehler 2002).');
        });
        $errors = new ErrorReporter(function (string $line): void {
            $this->log[] = $line;
        });

        return new Application($service, $tenant, $db, $errors);
    }
}
