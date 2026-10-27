<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Http;

use FirefightingEquipment\Api\V1\HealthHandler;
use FirefightingEquipment\Api\V1\TenantHandler;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Database\LazyConnection;
use FirefightingEquipment\Http\AppPath;
use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\Router;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Http\TenantResolver;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Integration\DomainTestCase;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(Application::class)]
#[CoversClass(HealthHandler::class)]
#[CoversClass(TenantHandler::class)]
#[CoversClass(TenantResolver::class)]
#[CoversClass(Routes::class)]
#[UsesClass(AppPath::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConnectionException::class)]
#[UsesClass(ConnectionFactory::class)]
#[UsesClass(DatabaseConfig::class)]
#[UsesClass(DatabaseError::class)]
#[UsesClass(ErrorReporter::class)]
#[UsesClass(HttpException::class)]
#[UsesClass(JsonResponse::class)]
#[UsesClass(LazyConnection::class)]
#[UsesClass(Request::class)]
#[UsesClass(RequestContext::class)]
#[UsesClass(Router::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantRepository::class)]
#[UsesClass(TenantSlug::class)]
final class ApplicationTest extends DomainTestCase
{
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();

        $repository = new TenantRepository($this->pdo());
        $repository->create(TenantSlug::fromString('immendingen'), TenantName::fromString('Feuerwehr Immendingen – Süd'));
        $repository->create(TenantSlug::fromString('tuttlingen'), TenantName::fromString('Feuerwehr Tuttlingen'));
        $repository->setActive(TenantSlug::fromString('tuttlingen'), false);
    }

    public function testServiceHealthIsOk(): void
    {
        $response = $this->handle('GET', '/firefighting/service/api/v1/health');

        self::assertSame(200, $response->statusCode());
        self::assertSame('{"status":"ok","database":"ok"}', $response->body());
    }

    public function testActiveTenantHealthIsOk(): void
    {
        $response = $this->handle('GET', '/firefighting/immendingen/api/v1/health');

        self::assertSame(200, $response->statusCode());
        self::assertSame('{"status":"ok","database":"ok"}', $response->body());
    }

    public function testActiveTenantEndpointReturnsSlugAndName(): void
    {
        $response = $this->handle('GET', '/firefighting/immendingen/api/v1/tenant/?x=1');

        self::assertSame(200, $response->statusCode());
        self::assertSame(
            '{"slug":"immendingen","name":"Feuerwehr Immendingen – Süd","accent_color":"#b71c1c",'
            . '"accent_color_is_default":true,"logo_url":null,"logo_urls":null}',
            $response->body(),
        );
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
    }

    public function testInactiveTenantIs403(): void
    {
        foreach (['api/v1/health', 'api/v1/tenant'] as $rest) {
            $response = $this->handle('GET', '/firefighting/tuttlingen/' . $rest);

            self::assertSame(403, $response->statusCode());
            self::assertSame(
                '{"error":"tenant_inactive","message":"Diese Stadt ist gesperrt."}',
                $response->body(),
            );
        }
    }

    public function testUnknownTenantIs404TenantNotFound(): void
    {
        foreach (['api/v1/health', 'api/v1/tenant'] as $rest) {
            $response = $this->handle('GET', '/firefighting/rottweil/' . $rest);

            self::assertSame(404, $response->statusCode());
            self::assertSame(
                '{"error":"tenant_not_found","message":"Diese Stadt ist nicht bekannt."}',
                $response->body(),
            );
        }
    }

    public function testUnknownTenantWithUnknownPathIsPlain404(): void
    {
        $response = $this->handle('GET', '/firefighting/rottweil/api/v1/unbekannt');

        self::assertSame(404, $response->statusCode());
        self::assertSame('not_found', self::decode($response)['error']);
    }

    public function testInvalidSlugIs404NotFound(): void
    {
        $response = $this->handle('GET', '/firefighting/Immendingen/api/v1/tenant');

        self::assertSame(404, $response->statusCode());
        self::assertSame('not_found', self::decode($response)['error']);
    }

    public function testTenantEndpointDoesNotExistUnderService(): void
    {
        $response = $this->handle('GET', '/firefighting/service/api/v1/tenant');

        self::assertSame(404, $response->statusCode());
        self::assertSame('not_found', self::decode($response)['error']);
    }

    public function testWrongMethodUnderTenantIs405(): void
    {
        $response = $this->handle('DELETE', '/firefighting/immendingen/api/v1/tenant');

        self::assertSame(405, $response->statusCode());
        self::assertSame('GET', $response->headers()['Allow']);
        self::assertSame('method_not_allowed', self::decode($response)['error']);
    }

    public function testServiceHealthWithUnreachableDatabaseIs503(): void
    {
        $config = TestDatabase::config();
        $missing = new DatabaseConfig($config->host, 'fe_gibt_es_nicht_test', $config->user, $config->pass);
        $db = new LazyConnection(static fn (): \PDO => new ConnectionFactory()->create($missing));

        $response = $this->application($db)->handle(new Request('GET', '/firefighting/service/api/v1/health'));

        self::assertSame(503, $response->statusCode());
        self::assertSame('{"status":"error","database":"error"}', $response->body());
        self::assertCount(1, $this->log);
        self::assertStringContainsString('Verbindung zur Datenbank fehlgeschlagen', $this->log[0]);
        self::assertStringNotContainsString("'" . $config->user . "'", $this->log[0]);
        if ($config->pass !== '') {
            self::assertStringNotContainsString($config->pass, $this->log[0]);
            self::assertStringNotContainsString($config->pass, $response->body());
        }
    }

    public function testTenantHealthWithUnreachableDatabaseIs503DatabaseUnavailable(): void
    {
        $db = new LazyConnection(static fn (): \PDO => throw new ConnectionException('weg'));

        $response = $this->application($db)->handle(new Request('GET', '/firefighting/immendingen/api/v1/health'));

        self::assertSame(503, $response->statusCode());
        self::assertSame('database_unavailable', self::decode($response)['error']);
    }

    private function handle(string $method, string $uri): JsonResponse
    {
        return $this->application(new LazyConnection(fn (): \PDO => $this->pdo()))->handle(new Request($method, $uri));
    }

    private function application(LazyConnection $db): Application
    {
        $errors = new ErrorReporter(function (string $line): void {
            $this->log[] = $line;
        });

        return new Application(Routes::service(), Routes::tenant(), $db, $errors);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(JsonResponse $response): array
    {
        return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
    }
}
