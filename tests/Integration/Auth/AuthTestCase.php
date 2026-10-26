<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Database\LazyConnection;
use FirefightingEquipment\Http\AppUrls;
use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\CsvResponse;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\MinimumDuration;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\Response;
use FirefightingEquipment\Http\Router;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Integration\User\AuthSchemaTestCase;
use FirefightingEquipment\Tests\Support\FakeMailer;
use FirefightingEquipment\Tests\Support\TestPasswordHasher;
use FirefightingEquipment\User\Password\PasswordHasher;

/**
 * Basis für Tests von Anmeldung und Sitzungen: zwei aktive Städte (immendingen, tuttlingen),
 * Konten mit echtem Argon2id-Hash von PASSWORD und Anfragen über die ganze Anwendung. Hash und
 * Anwendung verwenden minimale Argon2id-Parameter (TestPasswordHasher), außer ein Test übergibt
 * ausdrücklich einen anderen Hasher. Mails landen in $mailer (FakeMailer), Links beginnen mit
 * BASE_URL, und die Mindestdauer von password-reset/request ist 0 (kein Warten in Tests).
 */
abstract class AuthTestCase extends AuthSchemaTestCase
{
    protected const string PASSWORD = 'Korrekt-Pferd-Batterie-42';
    protected const string IP = '192.0.2.10';
    protected const string BASE_URL = 'http://localhost:8000';

    private static ?string $passwordHash = null;

    /** @var list<string> */
    protected array $log = [];

    protected FakeMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new FakeMailer();
        $this->createTenant('immendingen');
        $this->createTenant('tuttlingen');
    }

    protected function tenant(string $slug): Tenant
    {
        return new TenantRepository($this->pdo())->findBySlug(TenantSlug::fromString($slug))
            ?? throw new \LogicException('Stadt nicht gefunden.');
    }

    /**
     * Konto mit Passwort PASSWORD; Standard: aktiver Benutzer.
     *
     * @param string|null                $slug null = Super-Admin
     * @param array<string, scalar|null> $overrides
     */
    protected function account(?string $slug, string $email, array $overrides = []): int
    {
        $values = ['email' => $email, 'password_hash' => self::passwordHash(), 'name' => 'Erika Musterfrau', 'display_name' => 'Erika'];
        if ($slug === null) {
            $values['role'] = 'superadmin';
        }

        return $this->createUser($slug === null ? null : $this->tenant($slug)->id, [...$values, ...$overrides]);
    }

    /**
     * Registriertes Konto im Status unverified oder pending (braucht einen Einladungscode; dazu
     * wird ein aktiver Admin "einlader-<status>-<lokaler Teil>@example.org" angelegt).
     */
    protected function registeredAccount(string $slug, string $email, string $status): int
    {
        $tenantId = $this->tenant($slug)->id;
        $admin = $this->account($slug, 'einlader-' . $status . '-' . strtok($email, '@') . '@example.org', ['role' => 'admin']);
        $code = $this->createInvitationCode($tenantId, $admin);

        return $this->account($slug, $email, [
            'status' => $status,
            'invitation_code_id' => $code,
            'email_verified_at' => $status === 'unverified' ? null : self::CREATED_AT,
            'display_name' => null,
        ]);
    }

    protected static function passwordHash(): string
    {
        return self::$passwordHash ??= TestPasswordHasher::fast()->hash(self::PASSWORD);
    }

    protected function app(?Router $service = null, ?Router $tenant = null, ?PasswordHasher $hasher = null): Application
    {
        $errors = new ErrorReporter(function (string $line): void {
            $this->log[] = $line;
        });

        return new Application(
            $service ?? Routes::service(new MinimumDuration(0)),
            $tenant ?? Routes::tenant(new MinimumDuration(0)),
            new LazyConnection(fn (): \PDO => $this->pdo()),
            $errors,
            hasher: $hasher ?? TestPasswordHasher::fast(),
            mailer: $this->mailer,
            urls: AppUrls::fixed(self::BASE_URL),
        );
    }

    /**
     * Anfrage, deren Antwort JSON sein muss.
     *
     * @param array<string, mixed>|string|null $body Array wird als JSON gesendet; null = ohne Body
     * @param array<string, string>            $headers
     */
    protected function send(
        string $method,
        string $uri,
        array|string|null $body = null,
        ?string $cookie = null,
        array $headers = [],
        ?Application $app = null,
    ): JsonResponse {
        $response = $this->sendAny($method, $uri, $body, $cookie, $headers, $app);
        self::assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }

    /**
     * Anfrage mit beliebiger Antwort (JSON oder CSV-Download). Einen CSV-Download vor der nächsten
     * Datenbankabfrage mit responseBody() lesen (ungepufferte Abfrage, siehe UnbufferedQuery).
     *
     * @param array<string, mixed>|string|null $body
     * @param array<string, string>            $headers
     */
    protected function sendAny(
        string $method,
        string $uri,
        array|string|null $body = null,
        ?string $cookie = null,
        array $headers = [],
        ?Application $app = null,
    ): Response {
        if (is_array($body)) {
            $body = json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR);
        }
        if ($body !== null) {
            $headers += ['content-type' => 'application/json'];
        }
        $headers += ['user-agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36'];

        $request = new Request(
            $method,
            $uri,
            $headers,
            $cookie === null ? [] : ['ff_session' => $cookie],
            $body,
            remoteAddress: self::IP,
        );

        return ($app ?? $this->app())->handle($request);
    }

    /**
     * @param string|null $slug null = service
     */
    protected function login(?string $slug, string $email, string $password = self::PASSWORD): JsonResponse
    {
        return $this->send('POST', self::url($slug, 'auth/login'), ['email' => $email, 'password' => $password]);
    }

    /**
     * Meldet an und liefert das Cookie (schlägt fehl, wenn die Anmeldung nicht klappt).
     */
    protected function loginCookie(?string $slug, string $email): string
    {
        $response = $this->login($slug, $email);
        self::assertSame(200, $response->statusCode(), $response->body());

        return self::cookieValue($response) ?? self::fail('Kein Cookie gesetzt.');
    }

    protected static function url(?string $slug, string $rest): string
    {
        return '/firefighting/' . ($slug ?? 'service') . '/api/v1/' . $rest;
    }

    /**
     * Wert von ff_session aus Set-Cookie; "" beim Löschen, null ohne Set-Cookie.
     */
    protected static function cookieValue(JsonResponse $response): ?string
    {
        $header = $response->headers()['Set-Cookie'] ?? null;
        if ($header === null) {
            return null;
        }
        self::assertSame(1, preg_match('/^ff_session=([^;]*);/', $header, $m), $header);

        return $m[1];
    }

    /**
     * Inhalt einer Antwort; ein CSV-Download wird dabei vollständig geschrieben (nur einmal möglich).
     */
    protected static function responseBody(Response $response): string
    {
        if ($response instanceof JsonResponse) {
            return $response->body();
        }
        self::assertInstanceOf(CsvResponse::class, $response);
        $stream = fopen('php://memory', 'w+b') ?: self::fail('Kein Stream.');
        $response->writeTo($stream);
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function json(JsonResponse $response): array
    {
        return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
    }

    protected static function assertError(int $status, string $code, JsonResponse $response): void
    {
        self::assertSame($status, $response->statusCode(), $response->body());
        self::assertSame($code, self::json($response)['error'] ?? null, $response->body());
    }

    /**
     * Verschiebt die Zeitstempel einer Sitzung um $seconds in die Vergangenheit. Mit
     * $keepLastSeen bleibt last_seen_at (Sitzung gerade benutzt, aber alt: Höchstdauer).
     */
    protected function ageSession(int $sessionId, int $seconds, bool $keepLastSeen = false): void
    {
        $this->pdo()->prepare(
            'UPDATE fe_sessions SET created_at = created_at - INTERVAL ? SECOND,'
            . ' expires_at = expires_at - INTERVAL ? SECOND'
            . ($keepLastSeen ? '' : ', last_seen_at = last_seen_at - INTERVAL ? SECOND')
            . ' WHERE id = ?',
        )->execute($keepLastSeen ? [$seconds, $seconds, $sessionId] : [$seconds, $seconds, $seconds, $sessionId]);
    }

    protected function sessionIdOf(int $userId): int
    {
        $stmt = $this->pdo()->prepare('SELECT MAX(id) FROM fe_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    protected function sessionCountOf(int $userId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM fe_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }
}
