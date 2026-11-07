<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Claude;

use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\Response;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;

/**
 * Basis für Tests des Claude-Zugangs (OAuth-Server und mcp): Test-Stadt mit einem Benutzer
 * (Zugang „Ändern“) und einem Admin, Anfragen wie Claude (Registrierung, „Claude verbinden“,
 * Token-Endpunkt mit Formular, mcp mit Bearer).
 */
abstract class ClaudeOAuthTestCase extends AuthTestCase
{
    protected const string CITY = 'test';
    protected const string AREA = '/firefighting/test/';
    protected const string ISSUER = self::BASE_URL . '/firefighting/test';
    /** Adresse des Vermittlers im cgi-bin (OAuthUrls::RELAY_PATH). */
    protected const string RESOURCE = self::BASE_URL . '/cgi-bin/ff-mcp.pl/test';
    protected const string CALLBACK = 'https://claude.ai/api/mcp/auth_callback';
    protected const string EMAIL = 'erika@example.org';
    /** Beispiel aus RFC 7636, Anhang B */
    protected const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    protected const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    protected int $user;
    protected int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTenant(self::CITY);
        $this->user = $this->account(self::CITY, self::EMAIL, ['claude_access' => 'write']);
        $this->admin = $this->account(self::CITY, 'admin@example.org', ['role' => 'admin']);
    }

    /**
     * @param list<string> $redirectUris
     */
    protected function registerClient(array $redirectUris = [self::CALLBACK], string $city = self::CITY): string
    {
        $response = $this->send('POST', '/firefighting/' . $city . '/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);
        self::assertSame(201, $response->statusCode(), $response->body());

        return (string) self::json($response)['client_id'];
    }

    /**
     * Parameter der Adresse, wie Claude sie an „Claude verbinden“ übergibt.
     *
     * @param array<string, string|null> $overrides null = Parameter weglassen
     *
     * @return array<string, string>
     */
    protected static function params(string $clientId, array $overrides = []): array
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::CALLBACK,
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => 'zustand-123',
            'scope' => 'read write',
            'resource' => self::RESOURCE,
            ...$overrides,
        ];

        return array_filter($params, static fn (?string $v): bool => $v !== null);
    }

    /**
     * @param array<string, string> $params
     */
    protected function authorize(array $params, string $decision, ?string $email = self::EMAIL, string $password = self::PASSWORD): JsonResponse
    {
        $body = ['params' => $params, 'decision' => $decision];
        if ($email !== null) {
            $body += ['email' => $email, 'password' => $password];
        }

        return $this->send('POST', self::AREA . 'api/v1/oauth/authorize', $body);
    }

    /**
     * Code über „Verbinden“ (decision allow).
     *
     * @param array<string, string> $params
     */
    protected function code(array $params, string $email = self::EMAIL): string
    {
        $response = $this->authorize($params, 'allow', $email);
        self::assertSame(200, $response->statusCode(), $response->body());
        $query = self::redirectQuery((string) self::json($response)['redirect_url']);

        return $query['code'] ?? self::fail('Kein Code im Rücksprung.');
    }

    /**
     * @param array<string, string> $form
     */
    protected function token(array $form, string $path = 'oauth/token', string $city = self::CITY): JsonResponse
    {
        $response = $this->sendAny('POST', '/firefighting/' . $city . '/' . $path, http_build_query($form), headers: [
            'content-type' => 'application/x-www-form-urlencoded',
        ]);
        self::assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }

    /**
     * @return array<string, string>
     */
    protected static function exchangeForm(string $clientId, string $code, array $overrides = []): array
    {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::CALLBACK,
            'client_id' => $clientId,
            'code_verifier' => self::VERIFIER,
            'resource' => self::RESOURCE,
            ...$overrides,
        ];
    }

    /**
     * Ganzer Ablauf: registrieren, verbinden, Code einlösen.
     *
     * @return array{client_id: string, access_token: string, refresh_token: string, scope: string, expires_in: int}
     */
    protected function connect(string $email = self::EMAIL, string $scope = 'read write'): array
    {
        $clientId = $this->registerClient();
        $code = $this->code(self::params($clientId, ['scope' => $scope]), $email);
        $response = $this->token(self::exchangeForm($clientId, $code));
        self::assertSame(200, $response->statusCode(), $response->body());
        $tokens = self::json($response);

        return ['client_id' => $clientId, ...$tokens];
    }

    protected function refresh(string $clientId, string $refreshToken): JsonResponse
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => $clientId]);
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     */
    protected function mcp(?string $accessToken, array $message, array $headers = [], string $city = self::CITY): Response
    {
        if ($accessToken !== null) {
            $headers += ['authorization' => 'Bearer ' . $accessToken];
        }

        return $this->sendAny('POST', '/firefighting/' . $city . '/mcp', $message, headers: $headers + ['accept' => 'application/json, text/event-stream']);
    }

    /**
     * @return array<string, string>
     */
    protected static function redirectQuery(string $url): array
    {
        self::assertStringStartsWith(self::CALLBACK . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    protected function connectionCount(int $userId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM fe_claude_connections WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    protected function codeCount(int $userId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM fe_oauth_codes WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Verschiebt alle Zeitstempel der Verbindungen eines Kontos um $seconds in die Vergangenheit.
     *
     * @param list<string> $columns
     */
    protected function ageConnections(int $userId, int $seconds, array $columns = ['created_at', 'last_seen_at', 'expires_at', 'access_expires_at', 'rotated_at']): void
    {
        $set = implode(', ', array_map(static fn (string $c): string => sprintf('%1$s = %1$s - INTERVAL %2$d SECOND', $c, $seconds), $columns));
        $this->pdo()->prepare('UPDATE fe_claude_connections SET ' . $set . ' WHERE user_id = ?')->execute([$userId]);
    }

    /**
     * Alle Bytes, die in Tabellen und Protokoll stehen (für „nie im Klartext gespeichert“).
     */
    protected function storedText(): string
    {
        $text = implode("\n", $this->log);
        foreach (['fe_oauth_clients', 'fe_oauth_codes', 'fe_claude_connections', 'fe_auth_attempts'] as $table) {
            $text .= json_encode($this->pdo()->query('SELECT * FROM ' . $table)->fetchAll(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            foreach ($this->pdo()->query('SELECT * FROM ' . $table)->fetchAll() as $row) {
                $text .= implode('|', array_map('strval', $row));
            }
        }
        foreach ($this->mailer->sent as $mail) {
            $text .= $mail->subject . $mail->text;
        }

        return $text;
    }
}
