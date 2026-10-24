<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use FirefightingEquipment\Cli\PasswordPromptException;
use FirefightingEquipment\Cli\TerminalPasswordPrompt;
use FirefightingEquipment\Cli\TextTable;
use FirefightingEquipment\Cli\UserCommand;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantException;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Support\FakePasswordPrompt;
use FirefightingEquipment\Tests\Support\TestDatabase;
use FirefightingEquipment\User\DuplicateUserEmailException;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\Password\PasswordBlocklist;
use FirefightingEquipment\User\Password\PasswordContext;
use FirefightingEquipment\User\Password\PasswordHasher;
use FirefightingEquipment\User\Password\PasswordPolicy;
use FirefightingEquipment\User\Password\WeakPasswordException;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserException;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserRuleViolationException;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(UserCommand::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConnectionFactory::class)]
#[UsesClass(DatabaseConfig::class)]
#[UsesClass(DuplicateUserEmailException::class)]
#[UsesClass(EmailAddress::class)]
#[UsesClass(PasswordBlocklist::class)]
#[UsesClass(PasswordContext::class)]
#[UsesClass(PasswordHasher::class)]
#[UsesClass(PasswordPolicy::class)]
#[UsesClass(PasswordPromptException::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantException::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantNotFoundException::class)]
#[UsesClass(TenantRepository::class)]
#[UsesClass(TenantSlug::class)]
#[UsesClass(TerminalPasswordPrompt::class)]
#[UsesClass(TextTable::class)]
#[UsesClass(User::class)]
#[UsesClass(UserException::class)]
#[UsesClass(UserNotFoundException::class)]
#[UsesClass(UserRepository::class)]
#[UsesClass(UserRole::class)]
#[UsesClass(UserRuleViolationException::class)]
#[UsesClass(UserStatus::class)]
#[UsesClass(WeakPasswordException::class)]
final class UserCommandTest extends AuthSchemaTestCase
{
    private const string PASSWORD = 'Unsere Feuerwehr fährt rot';

    public function testCreateSuperAdminWithPasswordStdinThenList(): void
    {
        [$exitCode, $out, $err] = $this->runCommand(
            ['superadmin:create', '--email=Chef@Example.org', '--name=Erika Musterfrau', '--password-stdin'],
            stdin: self::PASSWORD . "\r\n",
        );

        self::assertSame(UserCommand::EXIT_OK, $exitCode, $err);
        self::assertMatchesRegularExpression('/^Super-Admin angelegt: chef@example\.org \(ID \d+\)\r?$/m', $out);
        self::assertSame('', $err);
        // Genau ein Zeilenende (auch CRLF) wird entfernt, der Rest gilt.
        self::assertTrue(password_verify(self::PASSWORD, $this->hashOf('chef@example.org')));
        self::assertStringStartsWith('$argon2id$', $this->hashOf('chef@example.org'));

        [$exitCode, $out] = $this->runCommand(['superadmin:list']);

        self::assertSame(UserCommand::EXIT_OK, $exitCode);
        $lines = explode(PHP_EOL, rtrim($out));
        self::assertCount(3, $lines);
        self::assertMatchesRegularExpression('/^ID\s+E-Mail\s+Status\s+Angelegt \(UTC\)\s+Name$/u', $lines[0]);
        self::assertMatchesRegularExpression(
            '/^\d+\s+chef@example\.org\s+aktiv\s+\d{4}-\d\d-\d\d \d\d:\d\d:\d\d  Erika Musterfrau$/u',
            $lines[1],
        );
        self::assertSame('1 Super-Admin.', $lines[2]);
        self::assertStringNotContainsString('argon2', $out);
    }

    public function testCreateSuperAdminInteractively(): void
    {
        $prompt = new FakePasswordPrompt([self::PASSWORD, self::PASSWORD]);

        [$exitCode, , $err] = $this->runCommand(['superadmin:create', '--email=chef@example.org', '--name=Erika'], prompt: $prompt);

        self::assertSame(UserCommand::EXIT_OK, $exitCode, $err);
        self::assertSame(['Neues Passwort: ', 'Passwort wiederholen: '], $prompt->asked);
        self::assertTrue(password_verify(self::PASSWORD, $this->hashOf('chef@example.org')));
    }

    public function testMismatchedRepetitionCreatesNothing(): void
    {
        $prompt = new FakePasswordPrompt([self::PASSWORD, self::PASSWORD . ' ']);

        [$exitCode, $out, $err] = $this->runCommand(['superadmin:create', '--email=chef@example.org', '--name=Erika'], prompt: $prompt);

        self::assertSame(UserCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $out);
        self::assertSame('FEHLER: Die Passwörter stimmen nicht überein.' . PHP_EOL, $err);
        self::assertSame(0, $this->countRows('fe_users'));
    }

    public function testWeakPasswordIsRejectedBeforeRepetition(): void
    {
        $prompt = new FakePasswordPrompt(['Feuerwehr2025!', 'Feuerwehr2025!']);

        [$exitCode, , $err] = $this->runCommand(['superadmin:create', '--email=chef@example.org', '--name=Erika'], prompt: $prompt);

        self::assertSame(UserCommand::EXIT_ERROR, $exitCode);
        self::assertSame(['Neues Passwort: '], $prompt->asked);
        self::assertStringContainsString('FEHLER: Dieses Passwort ist zu verbreitet', $err);
        self::assertStringNotContainsString('Feuerwehr2025!', $err);
        self::assertSame(0, $this->countRows('fe_users'));
    }

    public function testPasswordMustNotContainNameOrEmail(): void
    {
        [$exitCode, , $err] = $this->runCommand(
            ['superadmin:create', '--email=erika.musterfrau@example.org', '--name=Erika Musterfrau', '--password-stdin'],
            stdin: 'Musterfrau-im-Einsatz',
        );

        self::assertSame(UserCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('FEHLER: Das Passwort darf keine Teile der E-Mail-Adresse', $err);
        self::assertSame(0, $this->countRows('fe_users'));
    }

    public function testDuplicateEmailIsReportedBeforeAskingForPassword(): void
    {
        $this->createSuperadmin();
        $email = (string) $this->pdo()->query('SELECT email FROM fe_users')->fetchColumn();
        $prompt = new FakePasswordPrompt();

        [$exitCode, , $err] = $this->runCommand(['superadmin:create', '--email=' . $email, '--name=Erika'], prompt: $prompt);

        self::assertSame(UserCommand::EXIT_ERROR, $exitCode);
        self::assertSame(sprintf('FEHLER: Es gibt bereits ein Konto mit der E-Mail-Adresse „%s“.', $email) . PHP_EOL, $err);
        self::assertSame([], $prompt->asked);
    }

    public function testWithoutTerminalOrPasswordStdinNothingIsCreated(): void
    {
        // Ohne Fake: TerminalPasswordPrompt verweigert (Windows bzw. kein Terminal).
        [$exitCode, , $err] = $this->runCommand(['superadmin:create', '--email=chef@example.org', '--name=Erika'], prompt: null);

        self::assertSame(UserCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('--password-stdin', $err);
        self::assertSame(0, $this->countRows('fe_users'));
    }

    public function testChangePasswordEndsSessions(): void
    {
        $this->runCommand(['superadmin:create', '--email=chef@example.org', '--name=Erika', '--password-stdin'], stdin: self::PASSWORD);
        $id = (int) $this->pdo()->query("SELECT id FROM fe_users WHERE email = 'chef@example.org'")->fetchColumn();
        $this->insert('fe_sessions', ['user_id' => $id, 'token_hash' => self::hash('s'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 600)]);

        [$exitCode, $out, $err] = $this->runCommand(
            ['superadmin:password', '--email=CHEF@example.org', '--password-stdin'],
            stdin: "Lange Leitern für hohe Häuser\n",
        );

        self::assertSame(UserCommand::EXIT_OK, $exitCode, $err);
        self::assertSame('Passwort geändert: chef@example.org. Alle Sitzungen des Kontos wurden beendet.' . PHP_EOL, $out);
        self::assertTrue(password_verify('Lange Leitern für hohe Häuser', $this->hashOf('chef@example.org')));
        self::assertSame(0, $this->countRows('fe_sessions'));
    }

    public function testChangePasswordChecksNameOfAccount(): void
    {
        $this->runCommand(['superadmin:create', '--email=chef@example.org', '--name=Erika Brandmeisterin', '--password-stdin'], stdin: self::PASSWORD);

        [$exitCode, , $err] = $this->runCommand(
            ['superadmin:password', '--email=chef@example.org', '--password-stdin'],
            stdin: 'Brandmeisterin-2025',
        );

        self::assertSame(UserCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('Teile der E-Mail-Adresse, des Namens', $err);
        self::assertTrue(password_verify(self::PASSWORD, $this->hashOf('chef@example.org')));
    }

    public function testTenantAccountsAreNotSuperAdmins(): void
    {
        $tenantId = $this->createTenant();
        $this->createUser($tenantId, ['email' => 'admin@example.org', 'role' => 'admin']);

        [$exitCode, , $err] = $this->runCommand(['superadmin:lock', '--email=admin@example.org']);

        self::assertSame(UserCommand::EXIT_ERROR, $exitCode);
        self::assertSame('FEHLER: Kein Super-Admin mit der E-Mail-Adresse „admin@example.org“ gefunden.' . PHP_EOL, $err);
    }

    public function testLockAndUnlock(): void
    {
        $this->createSuperadmin();
        $this->runCommand(['superadmin:create', '--email=chef@example.org', '--name=Erika', '--password-stdin'], stdin: self::PASSWORD);

        [$exitCode, $out] = $this->runCommand(['superadmin:lock', '--email=chef@example.org']);
        self::assertSame(UserCommand::EXIT_OK, $exitCode);
        self::assertSame('Super-Admin gesperrt: chef@example.org. Alle Sitzungen des Kontos wurden beendet.' . PHP_EOL, $out);
        self::assertMatchesRegularExpression('/chef@example\.org\s+aktiv, gesperrt\s/u', $this->runCommand(['superadmin:list'])[1]);

        [, $out] = $this->runCommand(['superadmin:lock', '--email=chef@example.org']);
        self::assertSame('Super-Admin ist bereits gesperrt: chef@example.org' . PHP_EOL, $out);

        [$exitCode, $out] = $this->runCommand(['superadmin:unlock', '--email=chef@example.org']);
        self::assertSame(UserCommand::EXIT_OK, $exitCode);
        self::assertSame('Super-Admin entsperrt: chef@example.org' . PHP_EOL, $out);

        [, $out] = $this->runCommand(['superadmin:unlock', '--email=chef@example.org']);
        self::assertSame('Super-Admin ist nicht gesperrt: chef@example.org' . PHP_EOL, $out);
    }

    public function testLastSuperAdminCannotBeLocked(): void
    {
        $this->runCommand(['superadmin:create', '--email=chef@example.org', '--name=Erika', '--password-stdin'], stdin: self::PASSWORD);

        [$exitCode, $out, $err] = $this->runCommand(['superadmin:lock', '--email=chef@example.org']);

        self::assertSame(UserCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $out);
        self::assertStringContainsString('FEHLER: Das ist der letzte aktive, nicht gesperrte Super-Admin.', $err);
    }

    public function testListTenantAccounts(): void
    {
        $tenantId = $this->createTenant('immendingen');
        $otherId = $this->createTenant('tuttlingen');
        $admin = $this->createUser($tenantId, ['email' => 'admin@example.org', 'role' => 'admin', 'display_name' => 'Chefin']);
        $locked = $this->createUser($tenantId, ['email' => 'user@example.org', 'locked_at' => self::CREATED_AT, 'display_name' => 'Max']);
        $deleted = $this->createUser($tenantId);
        new UserRepository($this->pdo())->anonymize($deleted);
        $this->createUser($otherId, ['email' => 'fremd@example.org']);

        [$exitCode, $out, $err] = $this->runCommand(['list', '--tenant=immendingen']);

        self::assertSame(UserCommand::EXIT_OK, $exitCode, $err);
        $lines = explode(PHP_EOL, rtrim($out));
        self::assertCount(5, $lines);
        self::assertMatchesRegularExpression('/^ID\s+Rolle\s+Status\s+E-Mail\s+Angelegt \(UTC\)\s+Name$/u', $lines[0]);
        self::assertMatchesRegularExpression('/^' . $admin . '\s+Admin\s+aktiv\s+admin@example\.org\s+\S+ \S+  Chefin$/u', $lines[1]);
        self::assertMatchesRegularExpression('/^' . $locked . '\s+Benutzer\s+aktiv, gesperrt\s+user@example\.org\s+\S+ \S+  Max$/u', $lines[2]);
        self::assertMatchesRegularExpression('/^' . $deleted . '\s+Benutzer\s+gelöscht\s+–\s+\S+ \S+  Gelöschter Benutzer #' . $deleted . '$/u', $lines[3]);
        self::assertSame('3 Konten.', $lines[4]);
        self::assertStringNotContainsString('fremd@example.org', $out);
        self::assertStringNotContainsString('argon2', $out);
    }

    public function testListEmptyAndUnknownTenant(): void
    {
        $this->createTenant('immendingen');

        [$exitCode, $out] = $this->runCommand(['list', '--tenant=immendingen']);
        self::assertSame(UserCommand::EXIT_OK, $exitCode);
        self::assertSame('Keine Konten in immendingen.' . PHP_EOL, $out);

        [$exitCode, $out, $err] = $this->runCommand(['list', '--tenant=tuttlingen']);
        self::assertSame(UserCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $out);
        self::assertSame('FEHLER: Keine Stadt mit dem Kürzel „tuttlingen“ gefunden.' . PHP_EOL, $err);
    }

    public function testListWithoutSuperAdmins(): void
    {
        [$exitCode, $out] = $this->runCommand(['superadmin:list']);

        self::assertSame(UserCommand::EXIT_OK, $exitCode);
        self::assertSame('Keine Super-Admins vorhanden.' . PHP_EOL, $out);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runCommand(array $args, string $stdin = '', ?FakePasswordPrompt $prompt = new FakePasswordPrompt()): array
    {
        $in = self::memory();
        $out = self::memory();
        $err = self::memory();
        fwrite($in, $stdin);
        rewind($in);

        try {
            $exitCode = new UserCommand(TestDatabase::CONFIG_PATH, $in, $out, $err, $prompt)->run($args);

            rewind($out);
            rewind($err);

            return [$exitCode, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        } finally {
            fclose($in);
            fclose($out);
            fclose($err);
        }
    }

    /**
     * @return resource
     */
    private static function memory()
    {
        return fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
    }

    private function hashOf(string $email): string
    {
        $stmt = $this->pdo()->prepare('SELECT password_hash FROM fe_users WHERE email = ?');
        $stmt->execute([$email]);

        return (string) $stmt->fetchColumn();
    }
}
