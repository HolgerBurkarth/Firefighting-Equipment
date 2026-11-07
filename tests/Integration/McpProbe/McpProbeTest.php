<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\McpProbe;

use FirefightingEquipment\Api\V1\McpProbe\McpProbeHandler;
use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\MinimumDuration;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Mcp\Probe\McpProbeTrace;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use FirefightingEquipment\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Vorklärung zum Claude-Zugang (docs/PLAN-MCP.md, Runde 3): Metadaten, 401 mit WWW-Authenticate,
 * Registrierung ohne Speichern, Platzhalter der Anmeldeseite und Protokoll; nur in der Test-Stadt.
 */
#[CoversClass(McpProbeHandler::class)]
#[CoversClass(McpProbeTrace::class)]
final class McpProbeTest extends AuthTestCase
{
    private const string AREA = '/firefighting/test/';
    private const string ISSUER = self::BASE_URL . '/firefighting/test';

    private string $traceDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenant('test');
        $this->traceDir = sys_get_temp_dir() . '/ff-mcp-probe-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->traceDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->traceDir)) {
            rmdir($this->traceDir);
        }
        parent::tearDown();
    }

    public function testUnauthenticatedMcpCallPointsToResourceMetadata(): void
    {
        $response = $this->send('POST', self::AREA . 'mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], headers: [
            'mcp-protocol-version' => '2025-06-18',
            'accept' => 'application/json, text/event-stream',
        ], app: $this->probeApp());

        self::assertSame(401, $response->statusCode());
        self::assertSame(
            'Bearer resource_metadata="' . self::ISSUER . '/.well-known/oauth-protected-resource", scope="read write"',
            $response->headers()['WWW-Authenticate'],
        );
        self::assertSame('not_authenticated', self::json($response)['error']);

        $entry = $this->traceEntries()[0];
        self::assertSame('mcp', $entry['step']);
        self::assertSame('2026-11-07T10:00:00Z', $entry['at']);
        self::assertSame('initialize', $entry['details']['jsonrpc_method']);
        self::assertSame('2025-06-18', $entry['details']['protocol_version']);
        self::assertFalse($entry['details']['authorization']);
        self::assertSame(401, $entry['details']['status']);
    }

    public function testGetOnMcpIsMethodNotAllowed(): void
    {
        $response = $this->send('GET', self::AREA . 'mcp', app: $this->probeApp());

        self::assertSame(405, $response->statusCode());
        self::assertSame('POST', $response->headers()['Allow']);
    }

    public function testProtectedResourceMetadata(): void
    {
        $response = $this->send('GET', self::AREA . '.well-known/oauth-protected-resource', app: $this->probeApp());

        self::assertSame(200, $response->statusCode());
        $data = self::json($response);
        self::assertSame(self::ISSUER . '/mcp', $data['resource']);
        self::assertSame([self::ISSUER], $data['authorization_servers']);
        self::assertSame(['read', 'write'], $data['scopes_supported']);
        self::assertSame(['header'], $data['bearer_methods_supported']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function discoveryDocuments(): iterable
    {
        yield 'OpenID Discovery' => ['.well-known/openid-configuration', 'openid-configuration'];
        yield 'RFC 8414 angehängt' => ['.well-known/oauth-authorization-server', 'oauth-authorization-server'];
    }

    #[DataProvider('discoveryDocuments')]
    public function testAuthorizationServerMetadata(string $path, string $step): void
    {
        $response = $this->send('GET', self::AREA . $path, app: $this->probeApp());

        self::assertSame(200, $response->statusCode());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        $data = self::json($response);
        self::assertSame(self::ISSUER, $data['issuer']);
        self::assertSame(self::ISSUER . '/claude-verbinden', $data['authorization_endpoint']);
        self::assertSame(self::ISSUER . '/oauth/token', $data['token_endpoint']);
        self::assertSame(self::ISSUER . '/oauth/register', $data['registration_endpoint']);
        self::assertSame(self::ISSUER . '/.well-known/jwks.json', $data['jwks_uri']);
        self::assertSame(['S256'], $data['code_challenge_methods_supported']);
        self::assertSame(['code'], $data['response_types_supported']);
        self::assertSame(['none'], $data['token_endpoint_auth_methods_supported']);
        self::assertNotContains('openid', $data['scopes_supported']);
        self::assertSame($step, $this->traceEntries()[0]['step']);
    }

    public function testEmptyKeySet(): void
    {
        $response = $this->send('GET', self::AREA . '.well-known/jwks.json', app: $this->probeApp());

        self::assertSame(200, $response->statusCode());
        self::assertSame('{"keys":[]}', $response->body());
    }

    public function testRegistrationIssuesClientIdWithoutStoringIt(): void
    {
        $tablesBefore = $this->rowCounts();
        $response = $this->send('POST', self::AREA . 'oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], app: $this->probeApp());

        self::assertSame(201, $response->statusCode());
        $data = self::json($response);
        self::assertMatchesRegularExpression('/^probe-[0-9a-f]{24}$/D', $data['client_id']);
        self::assertSame(['https://claude.ai/api/mcp/auth_callback'], $data['redirect_uris']);
        self::assertSame('none', $data['token_endpoint_auth_method']);
        self::assertSame(1794045600, $data['client_id_issued_at']);
        self::assertSame($tablesBefore, $this->rowCounts());

        $details = $this->traceEntries()[0]['details'];
        self::assertSame('Claude', $details['client_name']);
        self::assertTrue($details['redirect_uris_allowed']);
        self::assertSame(201, $details['status']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function redirectUris(): iterable
    {
        yield 'claude.ai' => ['https://claude.ai/api/mcp/auth_callback', true];
        yield 'claude.com' => ['https://claude.com/api/mcp/auth_callback', true];
        yield 'localhost mit Port' => ['http://localhost:53682/callback', true];
        yield '127.0.0.1 mit Port' => ['http://127.0.0.1:8080/callback', true];
        yield 'localhost ohne Port' => ['http://localhost/callback', true];
        yield 'anderer Pfad' => ['https://claude.ai/api/mcp/auth_callback/x', false];
        yield 'http claude.ai' => ['http://claude.ai/api/mcp/auth_callback', false];
        yield 'fremde Domain' => ['https://claude.ai.example.org/api/mcp/auth_callback', false];
        yield 'localhost als Subdomain' => ['http://localhost.example.org/callback', false];
        yield 'https localhost' => ['https://localhost:8080/callback', false];
        yield 'Query' => ['http://localhost:8080/callback?x=1', false];
        yield 'Port mit führender Null' => ['http://localhost:08080/callback', false];
        yield 'IPv6' => ['http://[::1]:8080/callback', false];
    }

    #[DataProvider('redirectUris')]
    public function testRedirectUriRules(string $uri, bool $allowed): void
    {
        self::assertSame($allowed, McpProbeHandler::isAllowedRedirectUri($uri));
    }

    public function testAuthorizePlaceholderShowsChecks(): void
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => 'probe-abc',
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'state' => 'geheim',
            'scope' => 'read write',
            'resource' => self::ISSUER . '/mcp',
        ]);
        $response = $this->send('GET', self::AREA . 'claude-verbinden?' . $query, app: $this->probeApp());

        self::assertSame(200, $response->statusCode());
        $data = self::json($response);
        self::assertStringContainsString('Claude hat die Anmeldeseite der Test-Stadt erreicht', $data['vorklaerung']);
        self::assertSame([
            'client_id_from_registration' => true,
            'response_type_code' => true,
            'redirect_uri_allowed' => true,
            'pkce_s256' => true,
            'resource_matches' => true,
            'state_present' => true,
        ], $data['checks']);
        self::assertSame('read write', $data['received']['scope']);

        // Das Protokoll enthält weder state noch code_challenge noch User-Agent des Browsers.
        $details = $this->traceEntries()[0]['details'];
        self::assertSame('authorize', $this->traceEntries()[0]['step']);
        self::assertArrayNotHasKey('user_agent', $details);
        self::assertStringNotContainsString('geheim', json_encode($this->traceEntries(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('E9Melhoa', json_encode($this->traceEntries(), JSON_THROW_ON_ERROR));
    }

    public function testAuthorizePlaceholderReportsMissingPkce(): void
    {
        $response = $this->send('GET', self::AREA . 'claude-verbinden?response_type=code&client_id=x', app: $this->probeApp());

        $checks = self::json($response)['checks'];
        self::assertFalse($checks['client_id_from_registration']);
        self::assertFalse($checks['pkce_s256']);
        self::assertFalse($checks['redirect_uri_allowed']);
        self::assertFalse($checks['resource_matches']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function probeRoutes(): iterable
    {
        yield 'mcp' => ['POST', 'mcp'];
        yield 'Ressource' => ['GET', '.well-known/oauth-protected-resource'];
        yield 'OpenID' => ['GET', '.well-known/openid-configuration'];
        yield 'RFC 8414' => ['GET', '.well-known/oauth-authorization-server'];
        yield 'Schlüssel' => ['GET', '.well-known/jwks.json'];
        yield 'Registrierung' => ['POST', 'oauth/register'];
        yield 'Anmeldeseite' => ['GET', 'claude-verbinden'];
        yield 'Protokoll' => ['GET', 'api/v1/mcp-probe'];
    }

    #[DataProvider('probeRoutes')]
    public function testOnlyInTestCity(string $method, string $path): void
    {
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $cookie = $this->loginCookie('immendingen', 'admin@example.org');

        $response = $this->send($method, '/firefighting/immendingen/' . $path, $method === 'POST' ? [] : null, $cookie, app: $this->probeApp());

        self::assertSame(404, $response->statusCode());
        self::assertSame('not_found', self::json($response)['error']);
        self::assertArrayNotHasKey('WWW-Authenticate', $response->headers());
        self::assertSame([], glob($this->traceDir . '/*') ?: []);
    }

    public function testNotUnderService(): void
    {
        foreach (['.well-known/openid-configuration', '.well-known/oauth-protected-resource', 'claude-verbinden'] as $path) {
            $response = $this->send('GET', '/firefighting/service/' . $path, app: $this->probeApp());
            // Unter service gibt es die Routen nicht; claude-verbinden ist dort ohne Frontend (Test) 404.
            self::assertSame(404, $response->statusCode(), $path);
        }
    }

    public function testTraceReadableOnlyByAdminOfTestCity(): void
    {
        $this->send('GET', self::AREA . '.well-known/oauth-protected-resource', app: $this->probeApp());
        $this->account('test', 'admin@example.org', ['role' => 'admin']);
        $this->account('test', 'user@example.org');

        $admin = $this->send('GET', self::AREA . 'api/v1/mcp-probe', cookie: $this->loginCookie('test', 'admin@example.org'), app: $this->probeApp());
        self::assertSame(200, $admin->statusCode());
        $entries = self::json($admin)['entries'];
        self::assertCount(1, $entries);
        self::assertSame('protected-resource', $entries[0]['step']);
        self::assertSame(200, $entries[0]['details']['status']);
        self::assertIsString($entries[0]['details']['user_agent']);

        $user = $this->send('GET', self::AREA . 'api/v1/mcp-probe', cookie: $this->loginCookie('test', 'user@example.org'), app: $this->probeApp());
        self::assertSame(403, $user->statusCode());
        self::assertSame(401, $this->send('GET', self::AREA . 'api/v1/mcp-probe', app: $this->probeApp())->statusCode());
    }

    public function testTraceKeepsNewestEntriesAndCleansValues(): void
    {
        $trace = new McpProbeTrace($this->traceDir);
        $at = new \DateTimeImmutable('2026-11-07T11:00:00+01:00');
        for ($i = 1; $i <= McpProbeTrace::MAX_ENTRIES + 5; ++$i) {
            $trace->record('test', 'step-' . $i, $at, ['wert' => "a\r\nb\u{00e4}" . str_repeat('x', 300)]);
        }

        $entries = $trace->entries('test');
        self::assertCount(McpProbeTrace::MAX_ENTRIES, $entries);
        self::assertSame('step-6', $entries[0]['step']);
        self::assertSame('step-55', $entries[McpProbeTrace::MAX_ENTRIES - 1]['step']);
        self::assertSame('2026-11-07T10:00:00Z', $entries[0]['at']);
        self::assertStringStartsWith('a??b??xxx', $entries[0]['details']['wert']);
        self::assertSame(McpProbeTrace::MAX_VALUE_LENGTH + strlen('…'), strlen($entries[0]['details']['wert']));
        self::assertSame([], $trace->entries('andere'));
    }

    public function testBrokenTraceDoesNotBreakTheResponse(): void
    {
        // Ein Ordner kann nicht angelegt werden, wo eine Datei liegt.
        $file = $this->traceDir;
        file_put_contents($file, 'keine Datei');
        try {
            $app = $this->probeApp(new McpProbeTrace($file . '/unter'));
            $response = $this->send('GET', self::AREA . '.well-known/oauth-protected-resource', app: $app);

            self::assertSame(200, $response->statusCode());
            self::assertStringContainsString('mcp_probe_trace_failed', implode("\n", $this->log));
        } finally {
            unlink($file);
        }
    }

    private function probeApp(?McpProbeTrace $trace = null): Application
    {
        return $this->app(tenant: Routes::tenant(
            new MinimumDuration(0),
            new FixedClock('2026-11-07T10:00:00Z'),
            probeTrace: $trace ?? new McpProbeTrace($this->traceDir),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function traceEntries(): array
    {
        return new McpProbeTrace($this->traceDir)->entries('test');
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];
        foreach ($this->pdo()->query("SHOW TABLES LIKE 'fe\\_%'")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $counts[$table] = (int) $this->pdo()->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }

        return $counts;
    }

}
