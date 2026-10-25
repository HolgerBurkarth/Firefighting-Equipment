<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Tenant;

use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Cli\TenantCommand;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Tenant\DuplicateTenantSlugException;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantException;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Integration\DomainTestCase;
use FirefightingEquipment\Tests\Support\FakeMailer;
use FirefightingEquipment\Tests\Support\TestDatabase;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(TenantCommand::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConnectionFactory::class)]
#[UsesClass(DatabaseConfig::class)]
#[UsesClass(DuplicateTenantSlugException::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantException::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantNotFoundException::class)]
#[UsesClass(TenantRepository::class)]
#[UsesClass(TenantSlug::class)]
final class TenantCommandTest extends DomainTestCase
{
    private FakeMailer $mailer;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new FakeMailer();
    }

    public function testListWithoutTenants(): void
    {
        [$exitCode, $out, $err] = $this->runCommand(['list']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertSame('Keine Städte vorhanden.' . PHP_EOL, $out);
        self::assertSame('', $err);
    }

    public function testCreateThenList(): void
    {
        [$exitCode, $out, $err] = $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertMatchesRegularExpression('/^Stadt angelegt: immendingen \(ID \d+, „Feuerwehr Immendingen“\)\r?$/m', $out);
        self::assertSame('', $err);

        $this->runCommand(['create', '--slug=bad-duerrheim', '--name=  Feuerwehr Bad Dürrheim  ']);
        [$exitCode, $out] = $this->runCommand(['list']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        $lines = explode(PHP_EOL, rtrim($out));
        self::assertCount(4, $lines);
        self::assertMatchesRegularExpression('/^ID\s+Kürzel\s+Status\s+Angelegt \(UTC\)\s+Name$/u', $lines[0]);
        self::assertMatchesRegularExpression(
            '/^\d+\s+bad-duerrheim\s+aktiv\s+\d{4}-\d\d-\d\d \d\d:\d\d:\d\d  Feuerwehr Bad Dürrheim$/u',
            $lines[1],
        );
        self::assertMatchesRegularExpression('/^\d+\s+immendingen\s+aktiv\s+\S+ \S+  Feuerwehr Immendingen$/u', $lines[2]);
        self::assertSame('2 Städte.', $lines[3]);
        // Spalten stehen trotz Umlaut in der Überschrift untereinander.
        self::assertSame(mb_strpos($lines[0], 'Status'), mb_strpos($lines[1], 'aktiv'));
    }

    public function testDuplicateSlugGivesExitCode1(): void
    {
        $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);

        [$exitCode, $out, $err] = $this->runCommand(['create', '--slug=immendingen', '--name=Andere']);

        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $out);
        self::assertSame('FEHLER: Das Kürzel „immendingen“ ist bereits vergeben.' . PHP_EOL, $err);
        self::assertSame(1, $this->pdo()->query('SELECT COUNT(*) FROM fe_tenants')->fetchColumn());
    }

    public function testUppercaseSlugIsRejectedAndNothingIsCreated(): void
    {
        [$exitCode, , $err] = $this->runCommand(['create', '--slug=Immendingen', '--name=Feuerwehr Immendingen']);

        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('Gemeint ist vermutlich „immendingen“.', $err);
        self::assertSame(0, $this->pdo()->query('SELECT COUNT(*) FROM fe_tenants')->fetchColumn());
    }

    public function testDeactivateAndActivate(): void
    {
        $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);

        [$exitCode, $out] = $this->runCommand(['deactivate', '--slug=immendingen']);
        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertSame('Stadt gesperrt: immendingen. Alle Sitzungen ihrer Konten wurden beendet.' . PHP_EOL, $out);
        self::assertStringContainsString('immendingen  gesperrt', $this->runCommand(['list'])[1]);

        [$exitCode, $out] = $this->runCommand(['deactivate', '--slug=immendingen']);
        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertSame('Stadt ist bereits gesperrt: immendingen' . PHP_EOL, $out);

        [$exitCode, $out] = $this->runCommand(['activate', '--slug=immendingen']);
        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertSame('Stadt entsperrt: immendingen' . PHP_EOL, $out);
        self::assertStringContainsString('immendingen  aktiv', $this->runCommand(['list'])[1]);

        [$exitCode, $out] = $this->runCommand(['activate', '--slug=immendingen']);
        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertSame('Stadt ist bereits aktiv: immendingen' . PHP_EOL, $out);
    }

    public function testUnknownSlugGivesExitCode1(): void
    {
        [$exitCode, $out, $err] = $this->runCommand(['deactivate', '--slug=tuttlingen']);

        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $out);
        self::assertSame('FEHLER: Keine Stadt mit dem Kürzel „tuttlingen“ gefunden.' . PHP_EOL, $err);
    }

    public function testRename(): void
    {
        $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);

        [$exitCode, $out, $err] = $this->runCommand(['rename', '--slug=immendingen', '--name=Freiwillige Feuerwehr Immendingen']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode, $err);
        self::assertSame('Stadt umbenannt: immendingen („Freiwillige Feuerwehr Immendingen“)' . PHP_EOL, $out);
        self::assertStringContainsString('Freiwillige Feuerwehr Immendingen', $this->runCommand(['list'])[1]);

        [$exitCode, $out] = $this->runCommand(['rename', '--slug=immendingen', '--name=Freiwillige Feuerwehr Immendingen']);
        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertSame('Stadt heißt bereits „Freiwillige Feuerwehr Immendingen“: immendingen' . PHP_EOL, $out);

        [$exitCode, , $err] = $this->runCommand(['rename', '--slug=tuttlingen', '--name=Tuttlingen']);
        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertSame('FEHLER: Keine Stadt mit dem Kürzel „tuttlingen“ gefunden.' . PHP_EOL, $err);
    }

    public function testDeactivateEndsSessions(): void
    {
        $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);
        $user = $this->activeUser('immendingen', 'max@example.org');
        $this->pdo()->prepare('INSERT INTO fe_sessions (user_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 1 DAY)')
            ->execute([$user, random_bytes(32)]);

        self::assertSame(TenantCommand::EXIT_OK, $this->runCommand(['deactivate', '--slug=immendingen'])[0]);

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_sessions')->fetchColumn());
    }

    public function testInviteAdminCreatesInvitationAndSendsMail(): void
    {
        $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);

        [$exitCode, $out, $err] = $this->runCommand(['invite-admin', '--slug=immendingen', '--email=Kommandant@Example.org']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode, $err);
        self::assertSame('', $err);
        self::assertMatchesRegularExpression('/^Admin eingeladen: kommandant@example\.org \(Konto #\d+, Stadt immendingen\)\r?$/m', $out);
        self::assertStringContainsString('Einladung per E-Mail gesendet (Link 24 Stunden gültig).', $out);
        self::assertStringNotContainsString('#token=', $out, 'Link nur mit --print-link');
        $user = $this->user('immendingen', 'kommandant@example.org');
        self::assertSame(UserStatus::Invited, $user->status);
        $mail = $this->mailer->only();
        self::assertSame('kommandant@example.org', $mail->to->value);
        self::assertSame($user->id, $this->tokenUser(self::linkToken($mail->text)));
    }

    public function testInviteAdminForOpenInvitationRenewsLinkAndPrintsIt(): void
    {
        $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);
        $this->runCommand(['invite-admin', '--slug=immendingen', '--email=kommandant@example.org']);
        $old = self::linkToken($this->mailer->only()->text);

        [$exitCode, $out, $err] = $this->runCommand(['invite-admin', '--slug=immendingen', '--email=kommandant@example.org', '--print-link']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode, $err);
        self::assertStringContainsString('Einladung erneuert: kommandant@example.org', $out);
        self::assertStringContainsString('WARNUNG: Der Link ist ein Zugang zum Konto.', $err);
        $printed = self::linkToken($out);
        self::assertSame(self::linkToken($this->mailer->sent[1]->text)->value(), $printed->value(), 'gleicher Link in Mail und Ausgabe');
        self::assertNull($this->tokenUser($old), 'alter Link ungültig');
        self::assertSame($this->user('immendingen', 'kommandant@example.org')->id, $this->tokenUser($printed));
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_users')->fetchColumn());
    }

    public function testInviteAdminRefusesExistingAccount(): void
    {
        $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);
        $this->activeUser('immendingen', 'max@example.org');

        [$exitCode, $out, $err] = $this->runCommand(['invite-admin', '--slug=immendingen', '--email=max@example.org']);

        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $out);
        self::assertStringStartsWith('FEHLER: Für „max@example.org“ gibt es in dieser Stadt bereits ein Konto (Status: aktiv).', $err);
        self::assertSame([], $this->mailer->sent);
    }

    public function testInviteAdminRefusesLockedOrUnknownTenant(): void
    {
        $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);
        $this->runCommand(['deactivate', '--slug=immendingen']);

        [$exitCode, , $err] = $this->runCommand(['invite-admin', '--slug=immendingen', '--email=neu@example.org']);
        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertSame('FEHLER: Die Stadt ist gesperrt. Zuerst entsperren, dann einladen.' . PHP_EOL, $err);

        [$exitCode, , $err] = $this->runCommand(['invite-admin', '--slug=tuttlingen', '--email=neu@example.org']);
        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertSame('FEHLER: Keine Stadt mit dem Kürzel „tuttlingen“ gefunden.' . PHP_EOL, $err);

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_users')->fetchColumn());
        self::assertSame([], $this->mailer->sent);
    }

    public function testInviteAdminMailFailureIsHintAndExitCode0(): void
    {
        $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);
        $this->mailer = new FakeMailer(fail: true);

        [$exitCode, $out, $err] = $this->runCommand(['invite-admin', '--slug=immendingen', '--email=neu@example.org', '--print-link']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString('HINWEIS: Die Einladung konnte nicht per E-Mail gesendet werden', $err);
        self::assertSame($this->user('immendingen', 'neu@example.org')->id, $this->tokenUser(self::linkToken($out)), 'Link als Notfallweg');
        self::assertStringContainsString('mail_failed (admin_invitation, Konto #', $this->log[0]);
    }

    public function testConfigOptionOverridesDefault(): void
    {
        [$out, $err] = $this->streams();
        $command = new TenantCommand('gibt-es-nicht.php', $out, $err);

        try {
            self::assertSame(TenantCommand::EXIT_OK, $command->run(['list', '--config=' . TestDatabase::CONFIG_PATH]));
        } finally {
            fclose($out);
            fclose($err);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runCommand(array $args): array
    {
        [$out, $err] = $this->streams();

        $errors = new ErrorReporter(function (string $line): void {
            $this->log[] = $line;
        });
        $exitCode = new TenantCommand(TestDatabase::CONFIG_PATH, $out, $err, $this->mailer, $errors)->run($args);

        rewind($out);
        rewind($err);
        $result = [$exitCode, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        fclose($out);
        fclose($err);

        return $result;
    }

    private function activeUser(string $slug, string $email): int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM fe_tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $this->pdo()->prepare(
            'INSERT INTO fe_users (tenant_id, role, status, email, email_verified_at, password_hash, name, display_name)'
            . " VALUES (?, 'user', 'active', ?, UTC_TIMESTAMP(), ?, 'Max', 'Max')",
        )->execute([(int) $stmt->fetchColumn(), $email, '$argon2id$v=19$m=1024,t=1,p=1$test']);

        return (int) $this->pdo()->lastInsertId();
    }

    private function user(string $slug, string $email): User
    {
        $tenant = new TenantRepository($this->pdo())->findBySlug(TenantSlug::fromString($slug)) ?? self::fail('Stadt fehlt.');

        return new UserRepository($this->pdo())->findByTenantAndEmail($tenant, EmailAddress::fromString($email)) ?? self::fail('Konto fehlt.');
    }

    private function tokenUser(OneTimeToken $token): ?int
    {
        return new UserTokenRepository($this->pdo())->findUserId(TokenPurpose::SetPassword, $token);
    }

    private static function linkToken(string $text): OneTimeToken
    {
        self::assertSame(1, preg_match('~/firefighting/immendingen/einladung-annehmen#token=([A-Za-z0-9_-]{43})\r?$~m', $text, $m), $text);

        return OneTimeToken::fromString($m[1]) ?? self::fail('Ungültiges Token im Link.');
    }

    /**
     * @return array{resource, resource}
     */
    private function streams(): array
    {
        return [
            fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory'),
            fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory'),
        ];
    }
}
