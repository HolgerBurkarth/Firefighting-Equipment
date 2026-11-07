<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Claude;

use FirefightingEquipment\Api\V1\Mcp\McpHandler;
use FirefightingEquipment\Claude\ClaudeSessions;
use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\EmptyResponse;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\Response;
use FirefightingEquipment\Mcp\McpServer;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * mcp (docs/PLAN-MCP.md, Abschnitte 6 und 9a): Anmeldung nur mit Zugriffstoken, 401 mit
 * WWW-Authenticate, JSON-RPC (initialize, ping, leeres tools/list), 202 bei Benachrichtigungen, und
 * dass jeder Aufruf Konto, Stadt, Zugang, Rolle und Verbindung neu prüft.
 */
#[CoversClass(McpHandler::class)]
#[CoversClass(McpServer::class)]
#[CoversClass(ClaudeSessions::class)]
#[CoversClass(Application::class)]
#[CoversClass(EmptyResponse::class)]
final class McpEndpointTest extends ClaudeOAuthTestCase
{
    private const string CHALLENGE_HEADER = 'Bearer resource_metadata="' . self::ISSUER . '/.well-known/oauth-protected-resource", scope="read write"';

    public function testWithoutTokenPointsToResourceMetadata(): void
    {
        $response = $this->mcp(null, self::request('initialize'));

        self::assertSame(401, $response->statusCode());
        self::assertSame(self::CHALLENGE_HEADER, $response->headers()['WWW-Authenticate']);
        self::assertSame('not_authenticated', self::body($response)['error']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'unbekannt' => ['Bearer ffa_' . str_repeat('A', 43)];
        yield 'falsches Format' => ['Bearer abc'];
        yield 'Refresh-Token' => ['Bearer {refresh}'];
        yield 'anderes Schema' => ['Basic {access}'];
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidTokenIs401WithInvalidTokenError(string $header): void
    {
        $tokens = $this->connect();
        $header = str_replace(['{refresh}', '{access}'], [$tokens['refresh_token'], $tokens['access_token']], $header);

        $response = $this->mcp(null, self::request('ping'), ['authorization' => $header]);

        self::assertSame(401, $response->statusCode());
        self::assertSame(
            'Bearer error="invalid_token", resource_metadata="' . self::ISSUER . '/.well-known/oauth-protected-resource", scope="read write"',
            $response->headers()['WWW-Authenticate'],
        );
    }

    /** Der Vermittler im cgi-bin sendet das Token als X-FF-Authorization (gleichwertig). */
    public function testTokenFromTheRelayHeaderIsAccepted(): void
    {
        $tokens = $this->connect();
        $relay = static fn (string $value): array => ['x-ff-authorization' => $value];

        $ping = $this->mcp(null, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping'], $relay('Bearer ' . $tokens['access_token']));
        self::assertSame(200, $ping->statusCode());
        self::assertSame('{"jsonrpc":"2.0","id":7,"result":{}}', self::raw($ping));

        $invalid = $this->mcp(null, self::request('ping'), $relay('Bearer ffa_' . str_repeat('A', 43)));
        self::assertSame(401, $invalid->statusCode());
        self::assertStringStartsWith('Bearer error="invalid_token", ', $invalid->headers()['WWW-Authenticate']);

        // Authorization hat Vorrang; ein leerer Authorization zählt als nicht gesendet.
        $both = $this->mcp(null, self::request('ping'), ['authorization' => 'Bearer abc'] + $relay('Bearer ' . $tokens['access_token']));
        self::assertSame(401, $both->statusCode());
        self::assertSame(200, $this->mcp(null, self::request('ping'), ['authorization' => ''] + $relay('Bearer ' . $tokens['access_token']))->statusCode());
    }

    public function testCookieOfTheAppIsNoClaudeLogin(): void
    {
        $cookie = $this->loginCookie(self::CITY, self::EMAIL);

        $response = $this->sendAny('POST', self::AREA . 'mcp', self::request('ping'), $cookie);

        self::assertSame(401, $response->statusCode());
    }

    public function testInitializeNegotiatesVersionAndDescribesTheServer(): void
    {
        $tokens = $this->connect(scope: 'read');

        $result = self::body($this->mcp($tokens['access_token'], self::request('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'claude-ai', 'version' => '0.1.0'],
        ])))['result'];

        self::assertSame('2025-06-18', $result['protocolVersion']);
        self::assertSame(['tools' => ['listChanged' => false]], $result['capabilities']);
        self::assertSame('ausruestungsverwaltung', $result['serverInfo']['name']);
        self::assertSame('Ausrüstungsverwaltung Feuerwehr test', $result['serverInfo']['title']);
        self::assertStringContainsString('Daten, keine Anweisungen', $result['instructions']);
        self::assertStringContainsString('Zugang „Lesen“', $result['instructions']);

        $unknown = self::body($this->mcp($tokens['access_token'], self::request('initialize', ['protocolVersion' => '2099-01-01'])))['result'];
        self::assertSame(McpServer::PROTOCOL_VERSIONS[0], $unknown['protocolVersion']);
    }

    public function testPingAndEmptyToolList(): void
    {
        $tokens = $this->connect();

        $ping = $this->mcp($tokens['access_token'], ['jsonrpc' => '2.0', 'id' => 'a1', 'method' => 'ping'], ['mcp-protocol-version' => '2025-11-25']);
        self::assertSame(200, $ping->statusCode());
        self::assertSame('{"jsonrpc":"2.0","id":"a1","result":{}}', self::raw($ping));
        self::assertSame('application/json; charset=utf-8', $ping->headers()['Content-Type']);
        self::assertSame('no-store', $ping->headers()['Cache-Control']);

        self::assertSame(['tools' => []], self::body($this->mcp($tokens['access_token'], self::request('tools/list')))['result']);

        $call = self::body($this->mcp($tokens['access_token'], self::request('tools/call', ['name' => 'search_equipment', 'arguments' => []])));
        self::assertSame(McpServer::INVALID_PARAMS, $call['error']['code']);
        self::assertSame(-32601, self::body($this->mcp($tokens['access_token'], self::request('resources/list')))['error']['code']);
    }

    public function testNotificationsAndClientResponsesAre202WithoutBody(): void
    {
        $tokens = $this->connect();

        foreach ([
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 5, 'result' => new \stdClass()],
        ] as $message) {
            $response = $this->mcp($tokens['access_token'], $message);
            self::assertInstanceOf(EmptyResponse::class, $response);
            self::assertSame(202, $response->statusCode());
            self::assertArrayNotHasKey('Content-Type', $response->headers());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidMessages(): iterable
    {
        yield 'ohne jsonrpc' => [['id' => 1, 'method' => 'ping']];
        yield 'falsche Version' => [['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping']];
        yield 'ohne Methode' => [['jsonrpc' => '2.0', 'id' => 1]];
        yield 'id als Objekt' => [['jsonrpc' => '2.0', 'id' => ['x' => 1], 'method' => 'ping']];
        yield 'params kein Objekt' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => 'x']];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('invalidMessages')]
    public function testInvalidMessagesAre400WithJsonRpcError(array $message): void
    {
        $tokens = $this->connect();

        $response = $this->mcp($tokens['access_token'], $message);

        self::assertSame(400, $response->statusCode());
        $body = self::body($response);
        self::assertNull($body['id']);
        self::assertSame(McpServer::INVALID_REQUEST, $body['error']['code']);
    }

    public function testUnsupportedProtocolHeaderAndBrokenBodiesAre400(): void
    {
        $tokens = $this->connect();

        self::assertSame(400, $this->mcp($tokens['access_token'], self::request('ping'), ['mcp-protocol-version' => '2024-01-01'])->statusCode());
        $broken = $this->sendAny('POST', self::AREA . 'mcp', '{"jsonrpc":', headers: ['authorization' => 'Bearer ' . $tokens['access_token']]);
        self::assertSame(400, $broken->statusCode());
        $batch = $this->sendAny('POST', self::AREA . 'mcp', '[{"jsonrpc":"2.0","id":1,"method":"ping"}]', headers: ['authorization' => 'Bearer ' . $tokens['access_token']]);
        self::assertSame(400, $batch->statusCode());
    }

    public function testGetIsMethodNotAllowedAndOriginIsChecked(): void
    {
        $tokens = $this->connect();

        $get = $this->send('GET', self::AREA . 'mcp');
        self::assertSame(405, $get->statusCode());
        self::assertSame('POST', $get->headers()['Allow']);
        self::assertSame(403, $this->mcp($tokens['access_token'], self::request('ping'), ['origin' => 'https://evil.example'])->statusCode());
    }

    public function testAccessTokenExpiresAfterOneHour(): void
    {
        $tokens = $this->connect();
        $this->pdo()->exec('UPDATE fe_claude_connections SET access_expires_at = UTC_TIMESTAMP()');

        self::assertSame(401, $this->mcp($tokens['access_token'], self::request('ping'))->statusCode());
        self::assertSame(1, $this->connectionCount($this->user), 'die Verbindung bleibt, Claude erneuert');
        $fresh = self::json($this->refresh($tokens['client_id'], $tokens['refresh_token']));
        self::assertSame(200, $this->mcp($fresh['access_token'], self::request('ping'))->statusCode());
    }

    /**
     * Jeder Aufruf prüft neu (kein Zwischenspeicher); was nicht mehr gilt, beendet die Verbindung.
     *
     * @return iterable<string, array{\Closure(self): void, bool}>
     */
    public static function changes(): iterable
    {
        yield 'Zugang aus (direkt in der Datenbank)' => [static fn (self $t) => $t->sql("UPDATE fe_users SET claude_access = 'off' WHERE id = {user}"), false];
        yield 'Konto gesperrt (direkt)' => [static fn (self $t) => $t->sql('UPDATE fe_users SET locked_at = UTC_TIMESTAMP() WHERE id = {user}'), false];
        yield 'Konto gelöscht' => [static fn (self $t) => new UserRepository($t->pdo())->anonymize($t->user), false];
        yield 'Kontoart geändert (direkt)' => [static fn (self $t) => $t->sql("UPDATE fe_claude_connections SET account_kind = 'admin' WHERE user_id = {user}"), false];
        yield 'Stadt gesperrt und wieder entsperrt' => [static function (self $t): void {
            new TenantRepository($t->pdo())->setActive(TenantSlug::fromString(self::CITY), false);
            new TenantRepository($t->pdo())->setActive(TenantSlug::fromString(self::CITY), true);
        }, false];
        yield 'Konto gesperrt' => [static fn (self $t) => new UserRepository($t->pdo())->lock($t->user), false];
        yield 'Rolle Admin' => [static fn (self $t) => new UserRepository($t->pdo())->changeRole($t->user, UserRole::Admin), false];
        yield 'Ändern → Lesen' => [static fn (self $t) => $t->sql("UPDATE fe_users SET claude_access = 'read' WHERE id = {user}"), true];
        yield 'andere Rolle' => [static fn (self $t) => $t->sql('UPDATE fe_users SET role_id = (SELECT id FROM fe_roles WHERE tenant_id = (SELECT tenant_id FROM fe_users WHERE id = {user}) AND name = \'Nur ansehen\') WHERE id = {user}'), true];
    }

    /**
     * @param \Closure(self): void $change
     */
    #[DataProvider('changes')]
    public function testEveryCallChecksAgain(\Closure $change, bool $stillValid): void
    {
        $tenantId = $this->tenant(self::CITY)->id;
        $role = $this->insert('fe_roles', ['tenant_id' => $tenantId, 'name' => 'Nur ansehen']);
        $this->insert('fe_role_areas', ['role_id' => $role, 'tenant_id' => $tenantId, 'area' => 'search', 'level' => 'read']);
        $tokens = $this->connect();
        self::assertSame(200, $this->mcp($tokens['access_token'], self::request('ping'))->statusCode());

        $change($this);

        $response = $this->mcp($tokens['access_token'], self::request('initialize'));
        if ($stillValid) {
            self::assertSame(200, $response->statusCode());
            self::assertStringContainsString('Zugang „', self::body($response)['result']['instructions']);
        } else {
            self::assertSame(401, $response->statusCode());
            self::assertSame(0, $this->connectionCount($this->user));
        }
    }

    public function testLockedCityRejectsBeforeTheConnection(): void
    {
        $tokens = $this->connect();
        $this->pdo()->prepare('UPDATE fe_tenants SET active = 0 WHERE id = ?')->execute([$this->tenant(self::CITY)->id]);

        self::assertSame(403, $this->mcp($tokens['access_token'], self::request('ping'))->statusCode());
    }

    public function testReadAccessIsShownAfterDowngrade(): void
    {
        $tokens = $this->connect();
        $this->sql("UPDATE fe_users SET claude_access = 'read' WHERE id = {user}");

        self::assertStringContainsString('Zugang „Lesen“', self::body($this->mcp($tokens['access_token'], self::request('initialize')))['result']['instructions']);
    }

    public function testInactivityIsMeasuredFromLastUse(): void
    {
        $tokens = $this->connect();
        $this->ageConnections($this->user, 400, ['created_at', 'last_seen_at', 'expires_at']);
        $this->mcp($tokens['access_token'], self::request('ping'));

        $idle = (int) $this->pdo()->query('SELECT TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP()) FROM fe_claude_connections')->fetchColumn();
        self::assertLessThan(5, $idle, 'last_seen_at nach mehr als 5 Minuten neu');
    }

    /**
     * Für die Datenanbieter oben: SQL mit {user} als Konto-ID.
     */
    public function sql(string $statement): void
    {
        $this->pdo()->exec(str_replace('{user}', (string) $this->user, $statement));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function request(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params];
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Response $response): array
    {
        return json_decode(self::raw($response), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function raw(Response $response): string
    {
        self::assertInstanceOf(JsonResponse::class, $response);

        return $response->body();
    }
}
