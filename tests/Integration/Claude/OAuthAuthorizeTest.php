<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Claude;

use FirefightingEquipment\Api\V1\OAuth\AuthorizeHandler;
use FirefightingEquipment\Api\V1\OAuth\ClaudeTenant;
use FirefightingEquipment\Api\V1\OAuth\ClientRegistrationHandler;
use FirefightingEquipment\Api\V1\OAuth\OAuthMetadataHandler;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\OAuth\AuthorizationCodeRepository;
use FirefightingEquipment\OAuth\AuthorizationRequest;
use FirefightingEquipment\OAuth\OAuthClientRepository;
use FirefightingEquipment\OAuth\OAuthRegisterThrottle;
use FirefightingEquipment\OAuth\OAuthUrls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * OAuth-Server, Teil 1 (docs/PLAN-MCP.md, Abschnitte 5 und 9a): Metadaten, Registrierung und die
 * API der Seite „Claude verbinden“ (Prüfen, Ablehnen, Anmelden, Code). Rücksprung nur zu exakt
 * registrierten Adressen, state unverändert, nur in der Test-Stadt.
 */
#[CoversClass(OAuthMetadataHandler::class)]
#[CoversClass(ClientRegistrationHandler::class)]
#[CoversClass(AuthorizeHandler::class)]
#[CoversClass(ClaudeTenant::class)]
#[CoversClass(OAuthUrls::class)]
#[CoversClass(AuthorizationRequest::class)]
#[CoversClass(AuthorizationCodeRepository::class)]
#[CoversClass(OAuthClientRepository::class)]
#[CoversClass(OAuthRegisterThrottle::class)]
final class OAuthAuthorizeTest extends ClaudeOAuthTestCase
{
    // ---- Metadaten -------------------------------------------------------------------------------

    public function testProtectedResourceMetadata(): void
    {
        $response = $this->send('GET', self::AREA . '.well-known/oauth-protected-resource');

        self::assertSame(200, $response->statusCode());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        $data = self::json($response);
        self::assertSame(self::RESOURCE, $data['resource']);
        self::assertSame([self::ISSUER], $data['authorization_servers']);
        self::assertSame(['read', 'write'], $data['scopes_supported']);
        self::assertSame(['header'], $data['bearer_methods_supported']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function discoveryPaths(): iterable
    {
        yield 'OpenID Discovery' => ['.well-known/openid-configuration'];
        yield 'RFC 8414 angehängt' => ['.well-known/oauth-authorization-server'];
    }

    #[DataProvider('discoveryPaths')]
    public function testAuthorizationServerMetadata(string $path): void
    {
        $data = self::json($this->send('GET', self::AREA . $path));

        self::assertSame(self::ISSUER, $data['issuer']);
        self::assertSame(self::ISSUER . '/claude-verbinden', $data['authorization_endpoint']);
        self::assertSame(self::ISSUER . '/oauth/token', $data['token_endpoint']);
        self::assertSame(self::ISSUER . '/oauth/register', $data['registration_endpoint']);
        self::assertSame(self::ISSUER . '/oauth/revoke', $data['revocation_endpoint']);
        self::assertSame(['S256'], $data['code_challenge_methods_supported']);
        self::assertSame(['none'], $data['token_endpoint_auth_methods_supported']);
        self::assertSame(['authorization_code', 'refresh_token'], $data['grant_types_supported']);
        self::assertTrue($data['authorization_response_iss_parameter_supported']);
        self::assertNotContains('openid', $data['scopes_supported']);
        self::assertSame(['keys' => []], self::json($this->send('GET', self::AREA . '.well-known/jwks.json')));
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function claudeRoutes(): iterable
    {
        yield 'Ressource' => ['GET', '.well-known/oauth-protected-resource', null];
        yield 'Discovery' => ['GET', '.well-known/openid-configuration', null];
        yield 'Schlüssel' => ['GET', '.well-known/jwks.json', null];
        yield 'Registrierung' => ['POST', 'oauth/register', ['redirect_uris' => [self::CALLBACK]]];
        yield 'Prüfen' => ['POST', 'api/v1/oauth/authorize/check', ['params' => ['client_id' => 'x']]];
        yield 'Verbinden' => ['POST', 'api/v1/oauth/authorize', ['params' => ['client_id' => 'x'], 'decision' => 'deny']];
        yield 'mcp' => ['POST', 'mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('claudeRoutes')]
    public function testOnlyInTheTestCity(string $method, string $path, ?array $body): void
    {
        $this->account('immendingen', self::EMAIL, ['claude_access' => 'off']);

        self::assertError(404, 'not_found', $this->send($method, '/firefighting/immendingen/' . $path, $body));
        self::assertError(404, 'not_found', $this->send($method, '/firefighting/service/' . $path, $body));
        self::assertError(404, 'tenant_not_found', $this->send($method, '/firefighting/unbekannt/' . $path, $body));
    }

    public function testTokenRoutesOnlyInTheTestCity(): void
    {
        foreach (['oauth/token', 'oauth/revoke'] as $path) {
            self::assertError(404, 'not_found', $this->token(['grant_type' => 'refresh_token'], $path, 'immendingen'));
        }
    }

    // ---- Registrierung ---------------------------------------------------------------------------

    public function testRegistrationStoresAllowedClient(): void
    {
        $response = $this->send('POST', self::AREA . 'oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::CALLBACK],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => 'read write',
        ]);

        self::assertSame(201, $response->statusCode());
        $data = self::json($response);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}\z/', $data['client_id']);
        self::assertSame('Claude', $data['client_name']);
        self::assertSame([self::CALLBACK], $data['redirect_uris']);
        self::assertSame('none', $data['token_endpoint_auth_method']);
        self::assertNotNull(new OAuthClientRepository($this->pdo())->find($this->tenant(self::CITY)->id, $data['client_id']));
        self::assertNull(new OAuthClientRepository($this->pdo())->find($this->tenant('immendingen')->id, $data['client_id']));
    }

    public function testRegistrationErrorsUseOAuthFormat(): void
    {
        $response = $this->send('POST', self::AREA . 'oauth/register', ['redirect_uris' => ['https://evil.example/callback']]);

        self::assertSame(400, $response->statusCode());
        self::assertSame('invalid_redirect_uri', self::json($response)['error']);
        self::assertArrayHasKey('error_description', self::json($response));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_oauth_clients')->fetchColumn());
    }

    public function testRegistrationIsThrottledPerIp(): void
    {
        for ($i = 0; $i < OAuthRegisterThrottle::MAX_PER_IP; ++$i) {
            $this->registerClient();
        }

        $response = $this->send('POST', self::AREA . 'oauth/register', ['redirect_uris' => [self::CALLBACK]]);
        self::assertError(429, 'too_many_attempts', $response);
        self::assertArrayHasKey('Retry-After', $response->headers());
    }

    public function testRegistrationCleansUpStaleClients(): void
    {
        $stale = $this->registerClient();
        $this->pdo()->exec('UPDATE fe_oauth_clients SET created_at = created_at - INTERVAL 25 HOUR');
        $this->registerClient();

        self::assertNull(new OAuthClientRepository($this->pdo())->find($this->tenant(self::CITY)->id, $stale));
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_oauth_clients')->fetchColumn());
    }

    // ---- Prüfen der Anfrage ----------------------------------------------------------------------

    public function testCheckNamesCityClientTargetAndRequestedAccess(): void
    {
        $clientId = $this->registerClient([self::CALLBACK, 'http://localhost:33418/callback']);

        $response = $this->send('POST', self::AREA . 'api/v1/oauth/authorize/check', ['params' => self::params($clientId)]);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame([
            'tenant' => ['name' => 'Feuerwehr test'],
            'client' => ['name' => 'Claude'],
            'target' => ['host' => 'claude.ai', 'local' => false],
            'requested_access' => 'write',
            'requested_access_label' => 'Ändern',
        ], self::json($response));

        $local = self::json($this->send('POST', self::AREA . 'api/v1/oauth/authorize/check', [
            'params' => self::params($clientId, ['redirect_uri' => 'http://localhost:33418/callback', 'scope' => 'read']),
        ]));
        self::assertSame(['host' => 'localhost', 'local' => true], $local['target']);
        self::assertSame('read', $local['requested_access']);
    }

    /**
     * Client oder Rücksprung nicht exakt registriert: Fehler ohne Rücksprung (kein offener Redirect).
     *
     * @return iterable<string, array{array<string, string|null>}>
     */
    public static function requestsWithoutRedirect(): iterable
    {
        yield 'unbekannter Client' => [['client_id' => 'unbekannt']];
        yield 'ohne Client' => [['client_id' => null]];
        yield 'fremde Adresse' => [['redirect_uri' => 'https://evil.example/callback']];
        yield 'Präfix' => [['redirect_uri' => 'https://claude.ai/api/mcp/auth']];
        yield 'Verlängerung' => [['redirect_uri' => self::CALLBACK . '/x']];
        yield 'Query angehängt' => [['redirect_uri' => self::CALLBACK . '?a=b']];
        yield 'erlaubt, aber nicht registriert' => [['redirect_uri' => 'https://claude.com/api/mcp/auth_callback']];
        yield 'anderer Port' => [['redirect_uri' => 'http://localhost:9999/callback']];
        yield 'ohne Rücksprung' => [['redirect_uri' => null]];
        yield 'state zu lang' => [['state' => str_repeat('s', 501)]];
    }

    /**
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('requestsWithoutRedirect')]
    public function testInvalidClientOrRedirectNeverRedirects(array $overrides): void
    {
        $clientId = $this->registerClient([self::CALLBACK, 'http://localhost:33418/callback']);
        $params = self::params($clientId, $overrides);

        foreach (['check', 'deny', 'allow'] as $step) {
            $response = $step === 'check'
                ? $this->send('POST', self::AREA . 'api/v1/oauth/authorize/check', ['params' => $params])
                : $this->authorize($params, $step);
            self::assertError(400, 'invalid_request', $response);
            self::assertArrayNotHasKey('details', self::json($response), $step);
            self::assertArrayNotHasKey('redirect_url', self::json($response), $step);
        }
        self::assertSame(0, $this->codeCount($this->user));
    }

    public function testClientOfAnotherCityIsUnknown(): void
    {
        $this->createTenant('test-zwei');
        // Eine Registrierung gehört zu genau einer Stadt.
        $clientId = $this->registerClient();
        $tenantId = $this->tenant('test-zwei')->id;
        $this->pdo()->prepare('UPDATE fe_oauth_clients SET tenant_id = ? WHERE client_id = ?')->execute([$tenantId, $clientId]);

        self::assertError(400, 'invalid_request', $this->send('POST', self::AREA . 'api/v1/oauth/authorize/check', ['params' => self::params($clientId)]));
    }

    /**
     * Client und Rücksprung geprüft: Fehler führen mit error und state zurück zu Claude.
     *
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function requestsWithRedirect(): iterable
    {
        yield 'response_type token' => [['response_type' => 'token'], 'unsupported_response_type'];
        yield 'ohne PKCE' => [['code_challenge' => null, 'code_challenge_method' => null], 'invalid_request'];
        yield 'PKCE plain' => [['code_challenge_method' => 'plain'], 'invalid_request'];
        yield 'Challenge zu kurz' => [['code_challenge' => 'abc'], 'invalid_request'];
        yield 'fremde Ressource' => [['resource' => 'https://evil.example/mcp'], 'invalid_target'];
        yield 'Ressource anderer Stadt' => [['resource' => self::BASE_URL . '/firefighting/immendingen/mcp'], 'invalid_target'];
        yield 'unbekannter Scope' => [['scope' => 'read admin'], 'invalid_scope'];
        yield 'leerer Scope' => [['scope' => ' '], 'invalid_scope'];
    }

    /**
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('requestsWithRedirect')]
    public function testOtherErrorsRedirectWithErrorAndState(array $overrides, string $error): void
    {
        $clientId = $this->registerClient();
        $state = 'ä ö&x=1+#?';

        $response = $this->send('POST', self::AREA . 'api/v1/oauth/authorize/check', ['params' => self::params($clientId, [...$overrides, 'state' => $state])]);

        self::assertError(400, 'invalid_request', $response);
        $query = self::redirectQuery((string) self::json($response)['details']['redirect_url']);
        self::assertSame($error, $query['error']);
        self::assertSame($state, $query['state']);
        self::assertSame(self::ISSUER, $query['iss']);
        self::assertArrayNotHasKey('code', $query);
    }

    public function testMissingResourceAndScopeUseDefaults(): void
    {
        $clientId = $this->registerClient();

        $data = self::json($this->send('POST', self::AREA . 'api/v1/oauth/authorize/check', [
            'params' => self::params($clientId, ['resource' => null, 'scope' => null, 'state' => null]),
        ]));
        self::assertSame('write', $data['requested_access']);

        $query = self::redirectQuery((string) self::json($this->authorize(self::params($clientId, ['state' => null]), 'deny', null))['redirect_url']);
        self::assertArrayNotHasKey('state', $query);
    }

    // ---- Ablehnen, Anmelden, Code ----------------------------------------------------------------

    public function testDenyRedirectsWithAccessDeniedWithoutLogin(): void
    {
        $clientId = $this->registerClient();

        $response = $this->authorize(self::params($clientId), 'deny', null);

        self::assertSame(200, $response->statusCode(), $response->body());
        $query = self::redirectQuery((string) self::json($response)['redirect_url']);
        self::assertSame('access_denied', $query['error']);
        self::assertSame('zustand-123', $query['state']);
        self::assertSame(self::ISSUER, $query['iss']);
        self::assertSame(0, $this->codeCount($this->user));
    }

    public function testPreviewShowsGrantedAccessWithoutCode(): void
    {
        $clientId = $this->registerClient();

        $response = $this->authorize(self::params($clientId), 'preview');

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['access' => 'write', 'access_label' => 'Ändern', 'user' => ['display_name' => 'Erika']], self::json($response));
        self::assertSame(0, $this->codeCount($this->user));
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
        self::assertSame(0, $this->sessionCountOf($this->user), 'keine Sitzung (O7)');
    }

    public function testGrantedAccessIsAtMostTheAccountsAccess(): void
    {
        $clientId = $this->registerClient();
        $this->pdo()->prepare("UPDATE fe_users SET claude_access = 'read' WHERE id = ?")->execute([$this->user]);

        self::assertSame('read', self::json($this->authorize(self::params($clientId), 'preview'))['access']);
        $this->pdo()->prepare("UPDATE fe_users SET claude_access = 'write' WHERE id = ?")->execute([$this->user]);
        self::assertSame('read', self::json($this->authorize(self::params($clientId, ['scope' => 'read']), 'preview'))['access']);
    }

    public function testAllowCreatesShortLivedCodeBoundToTheRequest(): void
    {
        $clientId = $this->registerClient();

        $response = $this->authorize(self::params($clientId), 'allow');

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
        $query = self::redirectQuery((string) self::json($response)['redirect_url']);
        self::assertMatchesRegularExpression('/^ffc_[A-Za-z0-9_-]{43}\z/', $query['code']);
        self::assertSame('zustand-123', $query['state']);
        self::assertSame(self::ISSUER, $query['iss']);

        $row = $this->pdo()->query('SELECT user_id, redirect_uri, code_challenge, access, TIMESTAMPDIFF(SECOND, created_at, expires_at) AS lifetime, code_hash FROM fe_oauth_codes')->fetch();
        self::assertSame($this->user, (int) $row['user_id']);
        self::assertSame(self::CALLBACK, $row['redirect_uri']);
        self::assertSame(self::CHALLENGE, $row['code_challenge']);
        self::assertSame('write', $row['access']);
        self::assertSame(AuthorizationCodeRepository::LIFETIME_SECONDS, (int) $row['lifetime']);
        self::assertLessThanOrEqual(600, (int) $row['lifetime']);
        self::assertSame(hash('sha256', (string) base64_decode(strtr(substr($query['code'], 4), '-_', '+/'), true), true), $row['code_hash']);
        self::assertStringNotContainsString($query['code'], $this->storedText());
        self::assertStringNotContainsString(substr($query['code'], 4), $this->storedText());
    }

    public function testWrongPasswordAndUnknownEmailLookAlike(): void
    {
        $clientId = $this->registerClient();

        self::assertError(401, 'invalid_credentials', $this->authorize(self::params($clientId), 'allow', password: 'falsch-falsch-falsch'));
        self::assertError(401, 'invalid_credentials', $this->authorize(self::params($clientId), 'allow', 'niemand@example.org'));
        self::assertError(401, 'invalid_credentials', $this->authorize(self::params($clientId), 'preview', 'admin@example.org', 'falsch-falsch-falsch'));
        self::assertSame(0, $this->codeCount($this->user));
    }

    public function testAccountOfAnotherCityCannotConnect(): void
    {
        $clientId = $this->registerClient();
        $this->account('immendingen', 'fremd@example.org', ['claude_access' => 'off']);

        self::assertError(401, 'invalid_credentials', $this->authorize(self::params($clientId), 'allow', 'fremd@example.org'));
    }

    public function testWithoutClaudeAccessNoCodeAndNoRedirect(): void
    {
        $clientId = $this->registerClient();

        foreach (['preview', 'allow'] as $decision) {
            $response = $this->authorize(self::params($clientId), $decision, 'admin@example.org');
            self::assertError(403, 'claude_access_missing', $response);
            self::assertSame('Kein Claude-Zugang – bitte wenden Sie sich an Ihren Admin.', self::json($response)['message']);
            self::assertArrayNotHasKey('details', self::json($response));
        }
        self::assertSame(0, $this->codeCount($this->admin));
    }

    public function testLockedAccountIsRejected(): void
    {
        $clientId = $this->registerClient();
        $this->pdo()->prepare('UPDATE fe_users SET locked_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$this->user]);

        self::assertError(403, 'account_locked', $this->authorize(self::params($clientId), 'allow'));
        self::assertSame(0, $this->codeCount($this->user));
    }

    public function testLoginThrottleOfTheAppApplies(): void
    {
        $clientId = $this->registerClient();
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ACCOUNT; ++$i) {
            $this->authorize(self::params($clientId), 'preview', password: 'falsch-falsch-falsch');
        }

        $response = $this->authorize(self::params($clientId), 'allow');
        self::assertError(429, 'too_many_attempts', $response);
        self::assertArrayHasKey('Retry-After', $response->headers());
        // Dieselbe Bremse wie die Anmeldung der App.
        self::assertError(429, 'too_many_attempts', $this->login(self::CITY, self::EMAIL));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'ohne params' => [['decision' => 'deny']];
        yield 'params als Liste' => [['params' => ['a'], 'decision' => 'deny']];
        yield 'unbekannte Entscheidung' => [['params' => ['client_id' => 'x'], 'decision' => 'maybe']];
        yield 'allow ohne Passwort' => [['params' => ['client_id' => 'x'], 'decision' => 'allow', 'email' => self::EMAIL]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesAre400(array $body): void
    {
        $clientId = $this->registerClient();
        if (isset($body['params']['client_id'])) {
            $body['params'] = self::params($clientId);
        }

        self::assertError(400, 'invalid_request', $this->send('POST', self::AREA . 'api/v1/oauth/authorize', $body));
    }

    public function testForeignOriginAndFormsAreRejected(): void
    {
        $clientId = $this->registerClient();
        $body = ['params' => self::params($clientId), 'decision' => 'allow', 'email' => self::EMAIL, 'password' => self::PASSWORD];

        self::assertError(403, 'origin_not_allowed', $this->send('POST', self::AREA . 'api/v1/oauth/authorize', $body, headers: ['origin' => 'https://evil.example']));
        self::assertError(415, 'unsupported_media_type', $this->send('POST', self::AREA . 'api/v1/oauth/authorize', http_build_query($body), headers: [
            'content-type' => 'application/x-www-form-urlencoded',
        ]));
        self::assertSame(0, $this->codeCount($this->user));
    }
}
