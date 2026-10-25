<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Auth\RegisterHandler;
use FirefightingEquipment\Auth\RegistrationThrottle;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\MinimumDuration;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Invitation\InvitationCodeValue;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(RegisterHandler::class)]
#[CoversClass(RegistrationThrottle::class)]
final class RegistrationTest extends AuthTestCase
{
    private const string NEW_PASSWORD = 'Leiter-Schlauch-Pumpe-77';

    private int $admin;
    private int $codeId;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        [$this->codeId, $this->code] = $this->newCode(5);
    }

    public function testRegistrationCreatesUnverifiedAccountAndSendsLink(): void
    {
        $response = $this->register(['code' => strtolower(str_replace('-', ' ', $this->code))]);

        self::assertNeutral($response);
        $user = new UserRepository($this->pdo())->findByTenantAndEmail($this->tenant('immendingen'), self::email('max@example.org'));
        self::assertNotNull($user);
        self::assertSame(UserStatus::Unverified, $user->status);
        self::assertSame('Max Mustermann', $user->name?->value);
        self::assertSame(1, $this->useCount());

        $mail = $this->mailer->only();
        self::assertSame('max@example.org', $mail->to->value);
        self::assertSame('Bitte bestätigen Sie Ihre E-Mail-Adresse', $mail->subject);
        self::assertStringContainsString('feuerwehr-app@prodad.de zu Ihren Kontakten hinzu', $mail->text);
        self::assertSame(1, preg_match('~^' . preg_quote(self::BASE_URL, '~') . '/firefighting/immendingen/email-bestaetigen#token=([A-Za-z0-9_-]{43})$~m', $mail->text, $m));
        $stmt = $this->pdo()->prepare("SELECT token_hash FROM fe_user_tokens WHERE user_id = ? AND purpose = 'verify_email'");
        $stmt->execute([$user->id]);
        self::assertSame(hash('sha256', (string) base64_decode(strtr($m[1], '-_', '+/'), true), true), $stmt->fetchColumn());
        self::assertSame([], $this->log);

        self::assertError(403, 'email_not_verified', $this->login('immendingen', 'max@example.org', self::NEW_PASSWORD));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ownerStatuses(): iterable
    {
        yield 'aktiv' => ['active'];
        yield 'gesperrt' => ['locked'];
        yield 'unbestätigt' => ['unverified'];
        yield 'wartet' => ['pending'];
        yield 'eingeladen' => ['invited'];
    }

    /**
     * Gleiche Antwort wie bei einer neuen Adresse, aber kein Konto, keine Nutzung, Hinweis an den
     * Besitzer der Adresse.
     */
    #[DataProvider('ownerStatuses')]
    public function testExistingEmailGetsSameResponseAndHintMail(string $status): void
    {
        $owner = match ($status) {
            'locked' => $this->account('immendingen', 'max@example.org', ['locked_at' => self::CREATED_AT]),
            'invited' => $this->account('immendingen', 'max@example.org', ['role' => 'admin', 'status' => 'invited', 'password_hash' => null, 'email_verified_at' => null, 'name' => null, 'display_name' => null]),
            'unverified', 'pending' => $this->registeredAccount('immendingen', 'max@example.org', $status),
            default => $this->account('immendingen', 'max@example.org'),
        };
        $reference = $this->register(['email' => 'neu@example.org']);
        $this->mailer->sent = [];
        $users = $this->countRows('fe_users');
        $uses = $this->useCount();

        $response = $this->register(['email' => ' Max@Example.org ']);

        self::assertSame($reference->statusCode(), $response->statusCode());
        self::assertSame($reference->body(), $response->body());
        self::assertSame($reference->headers(), $response->headers());
        self::assertSame($users, $this->countRows('fe_users'));
        self::assertSame($uses, $this->useCount());
        $mail = $this->mailer->only();
        self::assertSame('max@example.org', $mail->to->value);
        self::assertSame('Registrierung mit Ihrer E-Mail-Adresse', $mail->subject);
        self::assertStringContainsString("\n" . self::BASE_URL . "/firefighting/immendingen/\n", $mail->text);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_user_tokens WHERE user_id = ' . $owner)->fetchColumn());
    }

    public function testSameEmailInOtherTenantCreatesSeparateAccount(): void
    {
        $this->account('tuttlingen', 'max@example.org');

        self::assertNeutral($this->register());

        self::assertSame('Bitte bestätigen Sie Ihre E-Mail-Adresse', $this->mailer->only()->subject);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableCodes(): iterable
    {
        yield 'falsches Format' => ['format'];
        yield 'unbekannt' => ['unbekannt'];
        yield 'andere Stadt' => ['andere Stadt'];
        yield 'abgelaufen' => ['abgelaufen'];
        yield 'aufgebraucht' => ['aufgebraucht'];
        yield 'widerrufen' => ['widerrufen'];
    }

    /**
     * Eine gemeinsame Meldung, auch für eine schon vergebene E-Mail (verrät nichts über Konten).
     */
    #[DataProvider('unusableCodes')]
    public function testUnusableCodeIsRejected(string $case): void
    {
        $code = match ($case) {
            'format' => 'ABCD-EFGH-JKM0',
            'unbekannt' => InvitationCodeValue::generate()->display(),
            'andere Stadt' => $this->newCode(5, 'tuttlingen')[1],
            default => $this->code,
        };
        match ($case) {
            'abgelaufen' => $this->pdo()->exec('UPDATE fe_invitation_codes SET created_at = created_at - INTERVAL 10 DAY, expires_at = created_at + INTERVAL 1 DAY'),
            'aufgebraucht' => $this->pdo()->exec('UPDATE fe_invitation_codes SET use_count = max_uses'),
            'widerrufen' => $this->pdo()->exec('UPDATE fe_invitation_codes SET revoked_at = UTC_TIMESTAMP()'),
            default => null,
        };
        $users = $this->countRows('fe_users');

        $new = $this->register(['code' => $code]);
        $existing = $this->register(['code' => $code, 'email' => 'admin@example.org']);

        self::assertError(400, 'invalid_invitation_code', $new);
        self::assertSame($new->body(), $existing->body());
        self::assertSame(
            'Der Einladungscode ist ungültig, abgelaufen, aufgebraucht oder widerrufen. Bitte wenden Sie sich an Ihre Feuerwehr.',
            self::json($new)['message'],
        );
        self::assertSame($users, $this->countRows('fe_users'));
        self::assertSame([], $this->mailer->sent);
        self::assertSame(2, $this->attempts(0));
    }

    public function testWeakPasswordIsRejectedWithoutUsingCodeOrCounting(): void
    {
        foreach (['kurz', 'mustermann-1234', 'immendingen-2026', 'Feuerwehr2025!'] as $password) {
            $response = $this->register(['password' => $password]);

            self::assertError(422, 'weak_password', $response);
            self::assertStringNotContainsString($password, $response->body());
        }

        self::assertSame(0, $this->useCount());
        self::assertSame(1, $this->countRows('fe_users'));
        self::assertSame(0, $this->countRows('fe_auth_attempts'));
        self::assertSame([], $this->mailer->sent);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'ohne Code' => [['code' => null], 'Das Feld "code" fehlt oder ist kein Text.'];
        yield 'ohne Passwort' => [['password' => null], 'Das Feld "password" fehlt oder ist kein Text.'];
        yield 'ungültige E-Mail' => [['email' => 'max@'], 'Bitte eine gültige E-Mail-Adresse angeben.'];
        yield 'leerer Name' => [['name' => '  '], 'Der Name darf nicht leer sein.'];
        yield 'Name mit Zeilenumbruch' => [['name' => "Max\nMustermann"], 'Der Name darf keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIs400(array $override, string $message): void
    {
        $response = $this->register($override);

        self::assertError(400, 'invalid_request', $response);
        self::assertSame($message, self::json($response)['message']);
        self::assertSame(0, $this->countRows('fe_auth_attempts'));
    }

    public function testOnlyUnderTenant(): void
    {
        self::assertError(404, 'not_found', $this->send('POST', self::url(null, 'auth/register'), self::body()));
    }

    public function testCodeIsExhaustedByRegistrations(): void
    {
        [, $code] = $this->newCode(1);

        self::assertNeutral($this->register(['code' => $code, 'email' => 'eins@example.org']));
        self::assertError(400, 'invalid_invitation_code', $this->register(['code' => $code, 'email' => 'zwei@example.org']));
    }

    public function testInvalidCodesAreThrottledPerIp(): void
    {
        for ($i = 0; $i < RegistrationThrottle::MAX_CODE_FAILURES_PER_IP; ++$i) {
            self::assertError(400, 'invalid_invitation_code', $this->register(['code' => InvitationCodeValue::generate()->display(), 'email' => "x{$i}@example.org"]));
        }

        $response = $this->register();

        self::assertError(429, 'too_many_attempts', $response);
        self::assertGreaterThan(RegistrationThrottle::CODE_FAILURE_WINDOW_SECONDS - 60, (int) $response->headers()['Retry-After']);
        self::assertSame(RegistrationThrottle::MAX_CODE_FAILURES_PER_IP, $this->attempts(0), 'abgewiesene Anfrage nicht gespeichert');
        self::assertSame(1, $this->countRows('fe_users'));
    }

    public function testRegistrationsAreThrottledPerEmail(): void
    {
        for ($i = 0; $i < RegistrationThrottle::MAX_REGISTRATIONS_PER_ACCOUNT; ++$i) {
            self::assertNeutral($this->register());
        }

        $response = $this->register();

        self::assertError(429, 'too_many_attempts', $response);
        self::assertGreaterThan(RegistrationThrottle::REGISTER_WINDOW_SECONDS - 60, (int) $response->headers()['Retry-After']);
        self::assertCount(RegistrationThrottle::MAX_REGISTRATIONS_PER_ACCOUNT, $this->mailer->sent, 'höchstens 3 Mails an eine Adresse');
        self::assertNeutral($this->register(['email' => 'andere@example.org']));
    }

    public function testRegistrationsAreThrottledPerIpAcrossTenants(): void
    {
        $ip = (string) inet_pton('192.0.2.0');
        for ($i = 0; $i < RegistrationThrottle::MAX_REGISTRATIONS_PER_IP; ++$i) {
            $this->insert('fe_auth_attempts', ['action' => 'register', 'tenant_id' => $this->tenant('tuttlingen')->id, 'email_hash' => self::hash("p{$i}"), 'ip_prefix' => $ip, 'succeeded' => 1]);
        }

        self::assertError(429, 'too_many_attempts', $this->register());
    }

    public function testStaleUnverifiedAccountIsReplaced(): void
    {
        $old = $this->registeredAccount('immendingen', 'max@example.org', 'unverified');
        $this->pdo()->exec('UPDATE fe_users SET created_at = created_at - INTERVAL 8 DAY WHERE id = ' . $old);

        self::assertNeutral($this->register());

        self::assertSame(UserStatus::Deleted, new UserRepository($this->pdo())->findById($old)?->status);
        self::assertSame('Bitte bestätigen Sie Ihre E-Mail-Adresse', $this->mailer->only()->subject);
    }

    public function testMailFailureIsLoggedButResponseStaysNeutral(): void
    {
        $this->mailer->fail = true;

        self::assertNeutral($this->register());

        self::assertCount(1, $this->log);
        self::assertStringStartsWith('firefighting: mail_failed (verify_email, Konto #', $this->log[0]);
        self::assertStringNotContainsString('max@example.org', $this->log[0]);
    }

    public function testResponseTakesAtLeastTheMinimumDuration(): void
    {
        $app = $this->app(tenant: Routes::tenant(new MinimumDuration(0.3)));

        foreach (['neu@example.org', 'admin@example.org'] as $email) {
            $start = hrtime(true);
            self::assertNeutral($this->send('POST', self::url('immendingen', 'auth/register'), self::body(['code' => $this->code, 'email' => $email]), app: $app));
            self::assertGreaterThanOrEqual(0.3, (hrtime(true) - $start) / 1e9);
        }
    }

    public function testDefaultMinimumDurationIsOneAndAHalfSeconds(): void
    {
        self::assertSame(1.5, RegisterHandler::MIN_DURATION_SECONDS);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function register(array $override = []): JsonResponse
    {
        return $this->send('POST', self::url('immendingen', 'auth/register'), self::body(['code' => $this->code, ...$override]));
    }

    /**
     * @param array<string, mixed> $override null entfernt ein Feld
     *
     * @return array<string, mixed>
     */
    private static function body(array $override = []): array
    {
        $body = [...['code' => 'ABCD-EFGH-JKMN', 'email' => 'max@example.org', 'password' => self::NEW_PASSWORD, 'name' => 'Max Mustermann'], ...$override];

        return array_filter($body, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @return array{int, string} ID und Klartext
     */
    private function newCode(int $maxUses, string $slug = 'immendingen'): array
    {
        $admin = $slug === 'immendingen' ? $this->admin : $this->account($slug, 'admin@example.org', ['role' => 'admin']);
        [$code, $value] = new InvitationCodeRepository($this->pdo())->create($this->tenant($slug), $admin, null, 7, $maxUses);

        return [$code->id, $value->display()];
    }

    private function useCount(): int
    {
        return (int) $this->pdo()->query('SELECT use_count FROM fe_invitation_codes WHERE id = ' . $this->codeId)->fetchColumn();
    }

    private function attempts(int $succeeded): int
    {
        return (int) $this->pdo()->query("SELECT COUNT(*) FROM fe_auth_attempts WHERE action = 'register' AND succeeded = " . $succeeded)->fetchColumn();
    }

    private static function email(string $value): EmailAddress
    {
        return EmailAddress::fromString($value);
    }

    private static function assertNeutral(JsonResponse $response): void
    {
        self::assertSame(202, $response->statusCode(), $response->body());
        self::assertSame(['message' => RegisterHandler::MESSAGE], self::json($response));
    }
}
