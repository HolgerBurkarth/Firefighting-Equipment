<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Claude;

use FirefightingEquipment\Api\V1\Auth\ClaudeConnectionsHandler;
use FirefightingEquipment\Api\V1\OAuth\TokenHandler;
use FirefightingEquipment\Claude\ClaudeConnectionRepository;
use FirefightingEquipment\Claude\ClaudeSessions;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\OAuth\OAuthError;
use FirefightingEquipment\OAuth\TokenService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * OAuth-Server, Teil 2 (docs/PLAN-MCP.md, Abschnitte 5 und 9a): Code einlösen (einmal, PKCE S256,
 * gebunden an Client, Rücksprung, Konto und Zugang; ein zweites Einlösen trennt die Verbindung),
 * Refresh mit Rotation und der Ausnahme aus O4, Widerruf, Mail „Neue Claude-Verbindung“, eigene
 * Verbindungen im Profil. Tokens und Codes stehen nie im Klartext in Tabellen, Protokoll oder Mails.
 */
#[CoversClass(TokenHandler::class)]
#[CoversClass(TokenService::class)]
#[CoversClass(OAuthError::class)]
#[CoversClass(ClaudeConnectionRepository::class)]
#[CoversClass(ClaudeSessions::class)]
#[CoversClass(ClaudeConnectionsHandler::class)]
#[CoversClass(MailTemplates::class)]
final class OAuthTokenTest extends ClaudeOAuthTestCase
{
    private const array ALL_TIMES = ['created_at', 'last_seen_at', 'expires_at', 'access_expires_at', 'rotated_at'];

    public function testCodeExchangeCreatesConnection(): void
    {
        $clientId = $this->registerClient();
        $code = $this->code(self::params($clientId));

        $response = $this->token(self::exchangeForm($clientId, $code));

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        $data = self::json($response);
        self::assertSame(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope'], array_keys($data));
        self::assertMatchesRegularExpression('/^ffa_[A-Za-z0-9_-]{43}\z/', $data['access_token']);
        self::assertMatchesRegularExpression('/^ffr_[A-Za-z0-9_-]{43}\z/', $data['refresh_token']);
        self::assertSame('Bearer', $data['token_type']);
        self::assertSame(3600, $data['expires_in']);
        self::assertSame('read write', $data['scope']);

        self::assertSame(1, $this->connectionCount($this->user));
        self::assertSame(0, $this->codeCount($this->user), 'Code eingelöst = gelöscht');
        $row = $this->pdo()->query('SELECT access, account_kind, TIMESTAMPDIFF(DAY, created_at, expires_at) AS days,'
            . ' TIMESTAMPDIFF(SECOND, created_at, access_expires_at) AS access_seconds FROM fe_claude_connections')->fetch();
        self::assertSame(['write', 'user', 90, 3600], [$row['access'], $row['account_kind'], (int) $row['days'], (int) $row['access_seconds']]);
        self::assertNotNull($this->pdo()->query('SELECT last_used_at FROM fe_oauth_clients')->fetchColumn());

        $stored = $this->storedText();
        foreach ([$code, $data['access_token'], $data['refresh_token']] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
            self::assertStringNotContainsString(substr($secret, 4), $stored);
        }
    }

    public function testNewConnectionMailGoesToTheAccount(): void
    {
        $this->connect(scope: 'read');

        $mail = $this->mailer->only();
        self::assertSame(self::EMAIL, $mail->to->value);
        self::assertSame('Neue Claude-Verbindung', $mail->subject);
        self::assertStringContainsString('Feuerwehr test', $mail->text);
        self::assertStringContainsString('Ziel: claude.ai', $mail->text);
        self::assertStringContainsString('Zugang: Lesen', $mail->text);
        self::assertStringContainsString('„Angemeldete Geräte“ → „Trennen“', $mail->text);
        self::assertStringContainsString(self::ISSUER . '/', $mail->text);
    }

    public function testAdminConnectionIsShortSessionClass(): void
    {
        $this->pdo()->prepare("UPDATE fe_users SET claude_access = 'read' WHERE id = ?")->execute([$this->admin]);

        $tokens = $this->connect('admin@example.org');

        self::assertSame('read', $tokens['scope']);
        $row = $this->pdo()->query('SELECT account_kind, TIMESTAMPDIFF(DAY, created_at, expires_at) AS days FROM fe_claude_connections')->fetch();
        self::assertSame(['admin', 30], [$row['account_kind'], (int) $row['days']]);
    }

    public function testSecondRedemptionFailsAndEndsTheConnection(): void
    {
        $clientId = $this->registerClient();
        $code = $this->code(self::params($clientId));
        $tokens = self::json($this->token(self::exchangeForm($clientId, $code)));
        self::assertSame(200, $this->mcp($tokens['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->statusCode());

        $again = $this->token(self::exchangeForm($clientId, $code));

        self::assertSame(400, $again->statusCode());
        self::assertSame('invalid_grant', self::json($again)['error']);
        self::assertStringNotContainsString(substr($code, 4), $again->body(), 'nie in Fehlermeldungen');
        self::assertSame(0, $this->connectionCount($this->user), 'alle Tokens aus dem Code ungültig');
        self::assertSame(401, $this->mcp($tokens['access_token'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'])->statusCode());
        self::assertSame('invalid_grant', self::json($this->refresh($clientId, $tokens['refresh_token']))['error']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function wrongBindings(): iterable
    {
        yield 'falscher Verifier' => [['code_verifier' => str_repeat('x', 43)]];
        yield 'Verifier = Challenge (plain)' => [['code_verifier' => self::CHALLENGE]];
        yield 'Verifier zu kurz' => [['code_verifier' => 'kurz']];
        yield 'anderer Rücksprung' => [['redirect_uri' => 'http://localhost:33418/callback']];
        yield 'Präfix des Rücksprungs' => [['redirect_uri' => 'https://claude.ai/api/mcp/auth']];
    }

    /**
     * Gebunden an Rücksprung und PKCE; ein falscher Versuch verbraucht den Code (nur einmal).
     *
     * @param array<string, string> $overrides
     */
    #[DataProvider('wrongBindings')]
    public function testCodeIsBoundToVerifierAndRedirect(array $overrides): void
    {
        $clientId = $this->registerClient([self::CALLBACK, 'http://localhost:33418/callback']);
        $code = $this->code(self::params($clientId));

        $response = $this->token(self::exchangeForm($clientId, $code, $overrides));

        self::assertSame('invalid_grant', self::json($response)['error'], $response->body());
        self::assertSame(0, $this->codeCount($this->user));
        self::assertSame('invalid_grant', self::json($this->token(self::exchangeForm($clientId, $code)))['error'], 'nicht ein zweites Mal');
        self::assertSame(0, $this->connectionCount($this->user));
    }

    public function testCodeIsBoundToTheClient(): void
    {
        $clientId = $this->registerClient();
        $other = $this->registerClient();
        $code = $this->code(self::params($clientId));

        self::assertSame('invalid_grant', self::json($this->token(self::exchangeForm($other, $code)))['error']);
        self::assertSame(0, $this->connectionCount($this->user));
    }

    public function testCodeExpiresAfterFiveMinutes(): void
    {
        $clientId = $this->registerClient();
        $code = $this->code(self::params($clientId));
        $this->pdo()->exec('UPDATE fe_oauth_codes SET created_at = created_at - INTERVAL 301 SECOND, expires_at = expires_at - INTERVAL 301 SECOND');

        self::assertSame('invalid_grant', self::json($this->token(self::exchangeForm($clientId, $code)))['error']);
        self::assertSame(0, $this->connectionCount($this->user));
    }

    public function testAccessIsCheckedAgainWhenTheCodeIsRedeemed(): void
    {
        $clientId = $this->registerClient();
        $code = $this->code(self::params($clientId));
        $this->pdo()->prepare("UPDATE fe_users SET claude_access = 'read' WHERE id = ?")->execute([$this->user]);

        $tokens = self::json($this->token(self::exchangeForm($clientId, $code)));
        self::assertSame('read', $tokens['scope']);

        // Zugang inzwischen „aus“: die Codes des Kontos sind gelöscht (M1), ein Code gilt nicht mehr.
        $code = $this->code(self::params($clientId));
        $this->pdo()->prepare("UPDATE fe_users SET claude_access = 'off' WHERE id = ?")->execute([$this->user]);
        self::assertSame('invalid_grant', self::json($this->token(self::exchangeForm($clientId, $code)))['error']);
    }

    /**
     * @return iterable<string, array{array<string, string>, int, string}>
     */
    public static function invalidTokenRequests(): iterable
    {
        yield 'unbekannter Grant' => [['grant_type' => 'password'], 400, 'unsupported_grant_type'];
        yield 'ohne Grant' => [[], 400, 'invalid_request'];
        yield 'unbekannter Client' => [['grant_type' => 'refresh_token', 'refresh_token' => 'ffr_x', 'client_id' => 'unbekannt'], 401, 'invalid_client'];
        yield 'ohne Client' => [['grant_type' => 'refresh_token', 'refresh_token' => 'ffr_x'], 400, 'invalid_request'];
        yield 'fremde Ressource' => [['grant_type' => 'authorization_code', 'resource' => 'https://evil.example/mcp'], 400, 'invalid_target'];
        yield 'frühere Adresse mcp' => [['grant_type' => 'authorization_code', 'resource' => self::ISSUER . '/mcp'], 400, 'invalid_target'];
        yield 'unbekannter Scope' => [['grant_type' => 'refresh_token', 'scope' => 'admin'], 400, 'invalid_scope'];
        yield 'Refresh-Token kein Format' => [['grant_type' => 'refresh_token', 'refresh_token' => 'abc', 'client_id' => '{client}'], 400, 'invalid_grant'];
        yield 'Code ohne Verifier' => [['grant_type' => 'authorization_code', 'code' => 'ffc_x', 'redirect_uri' => self::CALLBACK, 'client_id' => '{client}'], 400, 'invalid_request'];
    }

    /**
     * @param array<string, string> $form
     */
    #[DataProvider('invalidTokenRequests')]
    public function testInvalidTokenRequestsUseOAuthFormat(array $form, int $status, string $error): void
    {
        $clientId = $this->registerClient();
        $form = array_map(static fn (string $v): string => $v === '{client}' ? $clientId : $v, $form);

        $response = $this->token($form);

        self::assertSame($status, $response->statusCode(), $response->body());
        $data = self::json($response);
        self::assertSame($error, $data['error']);
        self::assertSame(['error', 'error_description'], array_keys($data));
    }

    public function testTokenEndpointAcceptsOnlyFormsAndAllowedOrigins(): void
    {
        $clientId = $this->registerClient();
        $code = $this->code(self::params($clientId));

        self::assertError(415, 'unsupported_media_type', $this->send('POST', self::AREA . 'oauth/token', self::exchangeForm($clientId, $code)));
        $foreign = $this->sendAny('POST', self::AREA . 'oauth/token', http_build_query(self::exchangeForm($clientId, $code)), headers: [
            'content-type' => 'application/x-www-form-urlencoded',
            'origin' => 'https://evil.example',
        ]);
        self::assertSame(403, $foreign->statusCode());
        self::assertSame(1, $this->codeCount($this->user), 'der Code ist noch nicht verbraucht');
        self::assertSame(200, $this->token(self::exchangeForm($clientId, $code))->statusCode());
    }

    // ---- Refresh ---------------------------------------------------------------------------------

    public function testRefreshRotatesBothTokens(): void
    {
        $first = $this->connect();

        $response = $this->refresh($first['client_id'], $first['refresh_token']);

        self::assertSame(200, $response->statusCode(), $response->body());
        $second = self::json($response);
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        self::assertNotSame($first['access_token'], $second['access_token']);
        self::assertSame(401, $this->mcp($first['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->statusCode());
        self::assertSame(200, $this->mcp($second['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->statusCode());
        self::assertSame(200, $this->refresh($first['client_id'], $second['refresh_token'])->statusCode());
        self::assertSame(1, $this->connectionCount($this->user));
    }

    public function testPreviousRefreshTokenWorksOnceWithinSixtySeconds(): void
    {
        $first = $this->connect();
        $lost = self::json($this->refresh($first['client_id'], $first['refresh_token']));

        // Antwort verloren: das vorige Token gilt innerhalb von 60 s einmal.
        $retry = $this->refresh($first['client_id'], $first['refresh_token']);
        self::assertSame(200, $retry->statusCode(), $retry->body());
        $current = self::json($retry);

        // Das Paar der verlorenen Antwort und das vorige gelten danach nicht mehr.
        self::assertSame(401, $this->mcp($lost['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->statusCode());
        self::assertSame('invalid_grant', self::json($this->refresh($first['client_id'], $first['refresh_token']))['error']);
        self::assertSame('invalid_grant', self::json($this->refresh($first['client_id'], $lost['refresh_token']))['error']);
        self::assertSame(1, $this->connectionCount($this->user));
        self::assertSame(200, $this->refresh($first['client_id'], $current['refresh_token'])->statusCode());
    }

    public function testPreviousRefreshTokenAfterSixtySecondsEndsTheConnection(): void
    {
        $first = $this->connect();
        $second = self::json($this->refresh($first['client_id'], $first['refresh_token']));
        $this->pdo()->exec('UPDATE fe_claude_connections SET rotated_at = rotated_at - INTERVAL 61 SECOND');

        self::assertSame('invalid_grant', self::json($this->refresh($first['client_id'], $first['refresh_token']))['error']);

        self::assertSame(0, $this->connectionCount($this->user), 'vermutlich gestohlen: Verbindung gelöscht');
        self::assertSame('invalid_grant', self::json($this->refresh($first['client_id'], $second['refresh_token']))['error']);
    }

    public function testRefreshTokenIsBoundToTheClient(): void
    {
        $tokens = $this->connect();
        $other = $this->registerClient();

        self::assertSame('invalid_grant', self::json($this->refresh($other, $tokens['refresh_token']))['error']);
        self::assertSame(200, $this->refresh($tokens['client_id'], $tokens['refresh_token'])->statusCode());
    }

    public function testRefreshChecksAccountAndAccessAgain(): void
    {
        $tokens = $this->connect();
        $this->pdo()->prepare("UPDATE fe_users SET claude_access = 'read' WHERE id = ?")->execute([$this->user]);

        $read = self::json($this->refresh($tokens['client_id'], $tokens['refresh_token']));
        self::assertSame('read', $read['scope'], 'Ändern → Lesen wirkt beim nächsten Erneuern');

        $this->pdo()->prepare('UPDATE fe_users SET locked_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$this->user]);
        self::assertSame('invalid_grant', self::json($this->refresh($tokens['client_id'], $read['refresh_token']))['error']);
        self::assertSame(0, $this->connectionCount($this->user));
    }

    /**
     * @return iterable<string, array{int, list<string>, bool}>
     */
    public static function lifetimes(): iterable
    {
        // Benutzer: lange Klasse, 30 Tage Inaktivität, 90 Tage höchstens.
        yield 'inaktiv 29 Tage' => [29 * 86400, self::ALL_TIMES, true];
        yield 'inaktiv 30 Tage' => [30 * 86400, self::ALL_TIMES, false];
        yield 'Höchstdauer' => [90 * 86400, ['created_at', 'expires_at', 'access_expires_at', 'rotated_at'], false];
    }

    /**
     * @param list<string> $columns
     */
    #[DataProvider('lifetimes')]
    public function testConnectionLifetimeFollowsTheSessionClass(int $seconds, array $columns, bool $valid): void
    {
        $tokens = $this->connect();
        $this->ageConnections($this->user, $seconds, $columns);

        $response = $this->refresh($tokens['client_id'], $tokens['refresh_token']);

        self::assertSame($valid ? 200 : 400, $response->statusCode(), $response->body());
        self::assertSame($valid ? 1 : 0, $this->connectionCount($this->user));
    }

    public function testStricterRoleShortensTheConnectionAtOnce(): void
    {
        $tokens = $this->connect();
        // 8 Tage ohne Nutzung: für Benutzer erlaubt (30), für eine Rolle mit Ändern nicht (7).
        $this->ageConnections($this->user, 8 * 86400);
        $tenantId = $this->tenant(self::CITY)->id;
        $role = $this->insert('fe_roles', ['tenant_id' => $tenantId, 'name' => 'Gerätewart']);
        $this->insert('fe_role_areas', ['role_id' => $role, 'tenant_id' => $tenantId, 'area' => 'devices', 'level' => 'write']);
        $this->pdo()->prepare('UPDATE fe_users SET role_id = ? WHERE id = ?')->execute([$role, $this->user]);

        self::assertSame('invalid_grant', self::json($this->refresh($tokens['client_id'], $tokens['refresh_token']))['error']);
    }

    public function testAtMostFiveConnectionsPerAccount(): void
    {
        $first = $this->connect();
        for ($i = 0; $i < ClaudeConnectionRepository::MAX_PER_USER; ++$i) {
            $this->ageConnections($this->user, 60);
            $this->connect();
        }

        self::assertSame(ClaudeConnectionRepository::MAX_PER_USER, $this->connectionCount($this->user));
        self::assertSame('invalid_grant', self::json($this->refresh($first['client_id'], $first['refresh_token']))['error'], 'die älteste fällt weg');
    }

    // ---- Widerruf --------------------------------------------------------------------------------

    public function testRevokeEndsTheConnection(): void
    {
        foreach (['access_token', 'refresh_token'] as $kind) {
            $tokens = $this->connect();

            $response = $this->token(['token' => $tokens[$kind], 'client_id' => $tokens['client_id']], 'oauth/revoke');

            self::assertSame(200, $response->statusCode(), $response->body());
            self::assertSame(0, $this->connectionCount($this->user), $kind);
        }
    }

    public function testRevokeIgnoresUnknownTokensAndOtherClients(): void
    {
        $tokens = $this->connect();
        $other = $this->registerClient();

        self::assertSame(200, $this->token(['token' => 'ffr_unbekannt', 'client_id' => $tokens['client_id']], 'oauth/revoke')->statusCode());
        self::assertSame(200, $this->token(['token' => $tokens['refresh_token'], 'client_id' => $other], 'oauth/revoke')->statusCode());
        self::assertSame(1, $this->connectionCount($this->user));
        self::assertSame('invalid_client', self::json($this->token(['token' => $tokens['refresh_token'], 'client_id' => 'x'], 'oauth/revoke'))['error']);
    }

    // ---- Eigene Verbindungen im Profil (O5) ------------------------------------------------------

    public function testOwnConnectionsAreListedAndCanBeEnded(): void
    {
        $tokens = $this->connect(scope: 'read');
        $this->pdo()->prepare('UPDATE fe_users SET claude_access = ? WHERE id = ?')->execute(['write', $this->admin]);
        $this->connect('admin@example.org');
        $cookie = $this->loginCookie(self::CITY, self::EMAIL);

        $list = self::json($this->send('GET', self::url(self::CITY, 'auth/claude-connections'), cookie: $cookie))['connections'];

        self::assertCount(1, $list);
        self::assertSame('Claude', $list[0]['client_name']);
        self::assertSame(['host' => 'claude.ai', 'local' => false], $list[0]['target']);
        self::assertSame(['read', 'Lesen'], [$list[0]['access'], $list[0]['access_label']]);
        self::assertMatchesRegularExpression('/Z\z/', $list[0]['last_used_at']);
        self::assertSame(['id', 'client_name', 'target', 'access', 'access_label', 'created_at', 'last_used_at'], array_keys($list[0]));

        $adminConnection = (int) $this->pdo()->query('SELECT id FROM fe_claude_connections WHERE user_id = ' . $this->admin)->fetchColumn();
        self::assertError(404, 'not_found', $this->send('DELETE', self::url(self::CITY, 'auth/claude-connections/' . $adminConnection), [], $cookie));
        self::assertSame(1, $this->connectionCount($this->admin), 'fremde Verbindung bleibt');

        $deleted = $this->send('DELETE', self::url(self::CITY, 'auth/claude-connections/' . $list[0]['id']), [], $cookie);
        self::assertSame(['deleted' => true], self::json($deleted));
        self::assertSame(0, $this->connectionCount($this->user));
        self::assertSame(401, $this->mcp($tokens['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->statusCode());
        self::assertSame([], self::json($this->send('GET', self::url(self::CITY, 'auth/claude-connections'), cookie: $cookie))['connections']);
    }

    public function testOwnConnectionsNeedASession(): void
    {
        self::assertError(401, 'not_authenticated', $this->send('GET', self::url(self::CITY, 'auth/claude-connections')));
        self::assertError(404, 'not_found', $this->send('GET', self::url(null, 'auth/claude-connections')));
    }
}
