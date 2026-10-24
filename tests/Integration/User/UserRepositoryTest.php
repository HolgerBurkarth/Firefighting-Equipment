<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Support\TestDatabase;
use FirefightingEquipment\User\DuplicateUserEmailException;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserException;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserRuleViolationException;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(UserRepository::class)]
#[UsesClass(DuplicateUserEmailException::class)]
#[UsesClass(EmailAddress::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantRepository::class)]
#[UsesClass(TenantSlug::class)]
#[UsesClass(User::class)]
#[UsesClass(UserException::class)]
#[UsesClass(UserNotFoundException::class)]
#[UsesClass(UserRole::class)]
#[UsesClass(UserRuleViolationException::class)]
#[UsesClass(UserStatus::class)]
final class UserRepositoryTest extends AuthSchemaTestCase
{
    private const int ER_LOCK_WAIT_TIMEOUT = 1205;

    private UserRepository $users;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo());
        $this->tenant = $this->tenant('immendingen');
    }

    // ---- Anlegen und Suchen ----------------------------------------------------------------

    public function testCreateSuperAdmin(): void
    {
        $user = $this->users->createSuperAdmin(
            EmailAddress::fromString('Chef@Example.org'),
            PersonName::fromString('Erika Musterfrau'),
            self::PASSWORD_HASH,
        );

        self::assertNull($user->tenantId);
        self::assertSame(UserRole::SuperAdmin, $user->role);
        self::assertSame(UserStatus::Active, $user->status);
        self::assertSame('chef@example.org', $user->email?->value);
        self::assertSame('Erika Musterfrau', $user->name?->value);
        self::assertSame('Erika Musterfrau', $user->displayName?->value);
        self::assertNotNull($user->emailVerifiedAt);
        self::assertNull($user->lockedAt);
        self::assertEqualsWithDelta(time(), $user->createdAt->getTimestamp(), 60, 'created_at in UTC');
        self::assertSame(self::PASSWORD_HASH, $this->passwordHash($user->id));
    }

    public function testSuperAdminEmailMustBeUniqueAmongSuperAdminsOnly(): void
    {
        $email = EmailAddress::fromString('chef@example.org');
        $this->users->createSuperAdmin($email, PersonName::fromString('Erika'), self::PASSWORD_HASH);
        $this->users->createInvitedAdmin($this->tenant, $email);

        $this->expectException(DuplicateUserEmailException::class);
        $this->expectExceptionMessageIsOrContains('Es gibt bereits ein Konto mit der E-Mail-Adresse „chef@example.org“.');
        $this->users->createSuperAdmin($email, PersonName::fromString('Max'), self::PASSWORD_HASH);
    }

    public function testCreateInvitedAdmin(): void
    {
        $user = $this->users->createInvitedAdmin($this->tenant, EmailAddress::fromString('admin@example.org'));

        self::assertSame($this->tenant->id, $user->tenantId);
        self::assertSame(UserRole::Admin, $user->role);
        self::assertSame(UserStatus::Invited, $user->status);
        self::assertNull($user->name);
        self::assertNull($user->displayName);
        self::assertNull($user->emailVerifiedAt);
        self::assertNull($this->passwordHash($user->id));
        self::assertSame('admin@example.org', $user->label());
    }

    public function testSameEmailInTwoTenantsButNotTwiceInOne(): void
    {
        $email = EmailAddress::fromString('admin@example.org');
        $other = $this->tenant('tuttlingen');

        $first = $this->users->createInvitedAdmin($this->tenant, $email);
        $second = $this->users->createInvitedAdmin($other, $email);
        self::assertNotSame($first->id, $second->id);

        $this->expectException(DuplicateUserEmailException::class);
        $this->users->createInvitedAdmin($this->tenant, $email);
    }

    public function testFind(): void
    {
        $email = EmailAddress::fromString('admin@example.org');
        $admin = $this->users->createInvitedAdmin($this->tenant, $email);
        $this->users->createSuperAdmin(EmailAddress::fromString('chef@example.org'), PersonName::fromString('Erika'), self::PASSWORD_HASH);

        self::assertSame($admin->id, $this->users->findById($admin->id)?->id);
        self::assertNull($this->users->findById(999_999));
        self::assertSame($admin->id, $this->users->findByTenantAndEmail($this->tenant, $email)?->id);
        self::assertNull($this->users->findByTenantAndEmail($this->tenant('tuttlingen'), $email));
        self::assertNull($this->users->findSuperAdminByEmail($email));
        self::assertNull($this->users->findByTenantAndEmail($this->tenant, EmailAddress::fromString('chef@example.org')));
        self::assertSame('Erika', $this->users->findSuperAdminByEmail(EmailAddress::fromString('chef@example.org'))?->label());
    }

    public function testEmailIsComparedBinary(): void
    {
        $this->users->createInvitedAdmin($this->tenant, EmailAddress::fromString('straße@example.org'));

        self::assertNull($this->users->findByTenantAndEmail($this->tenant, EmailAddress::fromString('strasse@example.org')));
        $this->users->createInvitedAdmin($this->tenant, EmailAddress::fromString('strasse@example.org'));
        self::assertCount(2, $this->users->listByTenant($this->tenant));
    }

    public function testListsAreSeparatedAndSortedById(): void
    {
        $b = $this->createUser($this->tenant->id);
        $a = $this->createUser($this->tenant->id, ['role' => 'admin']);
        $this->createUser($this->tenant('tuttlingen')->id);
        $s1 = $this->createSuperadmin();
        $s2 = $this->createSuperadmin();

        self::assertSame([$b, $a], array_map(static fn (User $u) => $u->id, $this->users->listByTenant($this->tenant)));
        self::assertSame([$s1, $s2], array_map(static fn (User $u) => $u->id, $this->users->listSuperAdmins()));
    }

    // ---- Sperren -----------------------------------------------------------------------------

    public function testLockEndsSessionsOfThisAccountOnly(): void
    {
        $this->createAdmin();
        $user = $this->createUser($this->tenant->id);
        $other = $this->createUser($this->tenant->id);
        $this->createSession($user);
        $this->createSession($user);
        $this->createSession($other);

        self::assertTrue($this->users->lock($user));

        self::assertNotNull($this->users->findById($user)?->lockedAt);
        self::assertSame(0, $this->sessionCount($user));
        self::assertSame(1, $this->sessionCount($other));
        self::assertFalse($this->users->lock($user), 'bereits gesperrt');
    }

    public function testUnlock(): void
    {
        $user = $this->createUser($this->tenant->id, ['locked_at' => self::CREATED_AT]);

        self::assertTrue($this->users->unlock($user));
        self::assertNull($this->users->findById($user)?->lockedAt);
        self::assertFalse($this->users->unlock($user), 'war nicht gesperrt');
    }

    public function testLastAdminCannotBeLocked(): void
    {
        $admin = $this->createAdmin();
        $this->createSession($admin);

        $this->assertRuleViolation('letzte aktive, nicht gesperrte Admin', fn () => $this->users->lock($admin));

        self::assertNull($this->users->findById($admin)?->lockedAt);
        self::assertSame(1, $this->sessionCount($admin), 'Sitzungen bleiben bei abgelehnter Änderung');
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function adminsThatDoNotCount(): iterable
    {
        yield 'gesperrter Admin' => [['role' => 'admin', 'locked_at' => self::CREATED_AT]];
        yield 'eingeladener Admin' => [['role' => 'admin', 'status' => 'invited', 'password_hash' => null, 'email_verified_at' => null]];
        yield 'gelöschter Admin' => [[
            'role' => 'admin', 'status' => 'deleted', 'email' => null, 'email_verified_at' => null,
            'password_hash' => null, 'name' => null, 'display_name' => null, 'deleted_at' => self::CREATED_AT,
        ]];
        yield 'aktiver Benutzer' => [['role' => 'user']];
    }

    /**
     * @param array<string, scalar|null> $other
     */
    #[DataProvider('adminsThatDoNotCount')]
    public function testOnlyActiveUnlockedAdminsCount(array $other): void
    {
        $admin = $this->createAdmin();
        $this->createUser($this->tenant->id, $other);

        $this->assertRuleViolation('letzte aktive', fn () => $this->users->lock($admin));
        $this->assertRuleViolation('letzte aktive', fn () => $this->users->changeRole($admin, UserRole::User));
        $this->assertRuleViolation('letzte aktive', fn () => $this->users->anonymize($admin));
    }

    public function testAdminsOfOtherTenantsDoNotCount(): void
    {
        $admin = $this->createAdmin();
        $this->createUser($this->tenant('tuttlingen')->id, ['role' => 'admin']);

        $this->assertRuleViolation('letzte aktive', fn () => $this->users->lock($admin));
    }

    public function testAdminCanBeLockedIfAnotherActiveAdminRemains(): void
    {
        $first = $this->createAdmin();
        $second = $this->createAdmin();

        self::assertTrue($this->users->lock($first));
        $this->assertRuleViolation('letzte aktive', fn () => $this->users->lock($second));
    }

    public function testUserCanBeLockedWithoutAnyAdmin(): void
    {
        $user = $this->createUser($this->tenant->id);

        self::assertTrue($this->users->lock($user));
    }

    public function testLastSuperAdminCannotBeLockedOrAnonymized(): void
    {
        $first = $this->createSuperadmin();
        $second = $this->createSuperadmin();

        self::assertTrue($this->users->lock($first));
        $this->assertRuleViolation('letzte aktive, nicht gesperrte Super-Admin', fn () => $this->users->lock($second));
        $this->assertRuleViolation('letzte aktive, nicht gesperrte Super-Admin', fn () => $this->users->anonymize($second));

        // Ein gesperrter Super-Admin zählt nicht mit und darf gelöscht werden.
        self::assertTrue($this->users->anonymize($first));
        self::assertSame(UserStatus::Active, $this->users->findById($second)?->status);
    }

    public function testTenantAdminsDoNotCountAsSuperAdmins(): void
    {
        $superAdmin = $this->createSuperadmin();
        $this->createAdmin();

        $this->assertRuleViolation('Super-Admin', fn () => $this->users->lock($superAdmin));
    }

    // ---- Rolle -------------------------------------------------------------------------------

    public function testPromoteAndDemoteEndSessions(): void
    {
        $this->createAdmin();
        $user = $this->createUser($this->tenant->id);
        $this->createSession($user);

        self::assertTrue($this->users->changeRole($user, UserRole::Admin));
        self::assertSame(UserRole::Admin, $this->users->findById($user)?->role);
        self::assertSame(0, $this->sessionCount($user));

        $this->createSession($user);
        self::assertTrue($this->users->changeRole($user, UserRole::User));
        self::assertSame(UserRole::User, $this->users->findById($user)?->role);
        self::assertSame(0, $this->sessionCount($user));
    }

    public function testSameRoleChangesNothing(): void
    {
        $user = $this->createUser($this->tenant->id);
        $this->createSession($user);

        self::assertFalse($this->users->changeRole($user, UserRole::User));
        self::assertSame(1, $this->sessionCount($user));
    }

    public function testLastAdminCannotBeDemoted(): void
    {
        $admin = $this->createAdmin();

        $this->assertRuleViolation('herabgestuft', fn () => $this->users->changeRole($admin, UserRole::User));
        self::assertSame(UserRole::Admin, $this->users->findById($admin)?->role);
    }

    public function testSuperAdminRoleIsNeitherGrantedNorRevoked(): void
    {
        $user = $this->createUser($this->tenant->id);
        $superAdmin = $this->createSuperadmin();
        $this->createSuperadmin();

        $this->assertRuleViolation('Rolle Super-Admin', fn () => $this->users->changeRole($user, UserRole::SuperAdmin));
        $this->assertRuleViolation('Rolle Super-Admin', fn () => $this->users->changeRole($superAdmin, UserRole::Admin));
    }

    // ---- Passwort ----------------------------------------------------------------------------

    public function testSetPasswordEndsSessions(): void
    {
        $user = $this->createUser($this->tenant->id);
        $this->createSession($user);
        $newHash = password_hash('Unsere Feuerwehr fährt rot', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1]);

        $this->users->setPassword($user, $newHash);

        self::assertSame($newHash, $this->passwordHash($user));
        self::assertSame(0, $this->sessionCount($user));
    }

    public function testSetPasswordForLastSuperAdminIsAllowed(): void
    {
        $superAdmin = $this->createSuperadmin();

        $this->users->setPassword($superAdmin, self::PASSWORD_HASH . 'x');

        self::assertSame(self::PASSWORD_HASH . 'x', $this->passwordHash($superAdmin));
    }

    public function testInvitedAccountGetsNoPasswordHere(): void
    {
        $invited = $this->users->createInvitedAdmin($this->tenant, EmailAddress::fromString('admin@example.org'));

        $this->assertRuleViolation('Einladung ist nicht abgeschlossen', fn () => $this->users->setPassword($invited->id, self::PASSWORD_HASH));
        self::assertNull($this->passwordHash($invited->id));
    }

    // ---- Anonymisieren -----------------------------------------------------------------------

    public function testAnonymizeRemovesAllPersonalData(): void
    {
        $this->createAdmin();
        $code = $this->createInvitationCode($this->tenant->id, $this->createAdmin());
        $user = $this->createUser($this->tenant->id, ['invitation_code_id' => $code, 'locked_at' => self::CREATED_AT]);
        $other = $this->createUser($this->tenant->id);
        $this->createSession($user);
        $this->createSession($other);
        $this->createToken($user, 'reset_password');
        $this->createToken($user, 'verify_email');
        $this->createToken($other, 'reset_password');

        self::assertTrue($this->users->anonymize($user));

        $row = $this->pdo()->query('SELECT * FROM fe_users WHERE id = ' . $user)->fetch();
        self::assertSame('deleted', $row['status']);
        self::assertNotNull($row['deleted_at']);
        foreach (['email', 'email_verified_at', 'password_hash', 'name', 'display_name'] as $column) {
            self::assertNull($row[$column], $column);
        }
        // Für die Historie bleiben ID, Stadt, Rolle und Herkunft erhalten.
        self::assertSame($this->tenant->id, $row['tenant_id']);
        self::assertSame('user', $row['role']);
        self::assertSame($code, $row['invitation_code_id']);

        self::assertSame(0, $this->sessionCount($user));
        self::assertSame(0, $this->tokenCount($user));
        self::assertSame(1, $this->sessionCount($other));
        self::assertSame(1, $this->tokenCount($other));
        self::assertSame('Gelöschter Benutzer #' . $user, $this->users->findById($user)?->label());

        self::assertFalse($this->users->anonymize($user), 'bereits gelöscht');
    }

    public function testAnonymizedEmailCanBeUsedAgain(): void
    {
        $this->createAdmin();
        $invited = $this->users->createInvitedAdmin($this->tenant, EmailAddress::fromString('admin@example.org'));

        $this->users->anonymize($invited->id);

        $again = $this->users->createInvitedAdmin($this->tenant, EmailAddress::fromString('admin@example.org'));
        self::assertNotSame($invited->id, $again->id);
    }

    public function testLastAdminCannotBeAnonymized(): void
    {
        $admin = $this->createAdmin();
        $this->createSession($admin);
        $this->createToken($admin, 'reset_password');

        $this->assertRuleViolation('gelöscht werden', fn () => $this->users->anonymize($admin));

        self::assertSame(UserStatus::Active, $this->users->findById($admin)?->status);
        self::assertSame(1, $this->sessionCount($admin));
        self::assertSame(1, $this->tokenCount($admin));
    }

    // ---- Unbekannte und gelöschte Konten -----------------------------------------------------

    /**
     * @return iterable<string, array{\Closure(UserRepository, int): mixed}>
     */
    public static function mutations(): iterable
    {
        yield 'lock' => [static fn (UserRepository $r, int $id) => $r->lock($id)];
        yield 'unlock' => [static fn (UserRepository $r, int $id) => $r->unlock($id)];
        yield 'changeRole' => [static fn (UserRepository $r, int $id) => $r->changeRole($id, UserRole::Admin)];
        yield 'setPassword' => [static fn (UserRepository $r, int $id) => $r->setPassword($id, self::PASSWORD_HASH)];
        yield 'anonymize' => [static fn (UserRepository $r, int $id) => $r->anonymize($id)];
    }

    /**
     * @param \Closure(UserRepository, int): mixed $mutation
     */
    #[DataProvider('mutations')]
    public function testUnknownAccount(\Closure $mutation): void
    {
        try {
            $mutation($this->users, 999_999);
            self::fail('UserNotFoundException erwartet.');
        } catch (UserNotFoundException $e) {
            self::assertSame('Kein Konto mit der ID 999999 gefunden.', $e->getMessage());
        }
        self::assertFalse($this->pdo()->inTransaction());
    }

    /**
     * @param \Closure(UserRepository, int): mixed $mutation
     */
    #[DataProvider('mutations')]
    public function testDeletedAccountCannotBeChanged(\Closure $mutation): void
    {
        $user = $this->createUser($this->tenant->id);
        $this->users->anonymize($user);

        try {
            $result = $mutation($this->users, $user);
            self::assertFalse($result, 'Nur anonymize() darf ohne Fehler zurückkehren (nichts geändert).');
        } catch (UserRuleViolationException $e) {
            self::assertSame(sprintf('Das Konto #%d ist gelöscht und kann nicht mehr geändert werden.', $user), $e->getMessage());
        }
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testRefusesOpenTransaction(): void
    {
        $user = $this->createUser($this->tenant->id);
        $this->pdo()->beginTransaction();

        try {
            $this->expectException(\LogicException::class);
            $this->users->lock($user);
        } finally {
            $this->pdo()->rollBack();
        }
    }

    // ---- Gleichzeitige Änderungen ------------------------------------------------------------

    public function testChangesWaitForLockedAccountsOfTheSameTenantOnly(): void
    {
        $first = $this->createAdmin();
        $second = $this->createAdmin();
        $otherTenant = $this->tenant('tuttlingen');
        $this->createUser($otherTenant->id, ['role' => 'admin']);
        $otherUser = $this->createUser($otherTenant->id);

        // Eine zweite Verbindung hält (wie eine laufende Änderung) die Sperre auf dem zweiten Admin.
        $concurrent = TestDatabase::connect();
        $concurrent->beginTransaction();
        $concurrent->query('SELECT id FROM fe_users WHERE id = ' . $second . ' FOR UPDATE')->fetchAll();
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            try {
                $this->users->lock($first);
                self::fail('Die Änderung hätte auf die Sperre warten müssen.');
            } catch (\PDOException $e) {
                self::assertSame(self::ER_LOCK_WAIT_TIMEOUT, $e->errorInfo[1] ?? null, $e->getMessage());
            }
            self::assertFalse($this->pdo()->inTransaction(), 'Transaktion zurückgerollt');

            // Konten anderer Städte sind nicht betroffen.
            self::assertTrue($this->users->lock($otherUser));
        } finally {
            $concurrent->rollBack();
        }

        self::assertNull($this->users->findById($first)?->lockedAt);
        self::assertTrue($this->users->lock($first));
    }

    // ---- Hilfen ------------------------------------------------------------------------------

    private function tenant(string $slug): Tenant
    {
        $this->createTenant($slug);

        return new TenantRepository($this->pdo())->findBySlug(TenantSlug::fromString($slug))
            ?? throw new \LogicException('Stadt nicht gefunden.');
    }

    private function createAdmin(): int
    {
        return $this->createUser($this->tenant->id, ['role' => 'admin']);
    }

    private function createSession(int $userId): void
    {
        static $counter = 0;
        $this->insert('fe_sessions', [
            'user_id' => $userId,
            'token_hash' => self::hash('session-' . ++$counter),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
    }

    private function createToken(int $userId, string $purpose): void
    {
        static $counter = 0;
        $this->insert('fe_user_tokens', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => self::hash('token-' . ++$counter),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 1800),
        ]);
    }

    private function sessionCount(int $userId): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_sessions WHERE user_id = ' . $userId)->fetchColumn();
    }

    private function tokenCount(int $userId): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_user_tokens WHERE user_id = ' . $userId)->fetchColumn();
    }

    private function passwordHash(int $userId): ?string
    {
        $hash = $this->pdo()->query('SELECT password_hash FROM fe_users WHERE id = ' . $userId)->fetchColumn();

        return $hash === null ? null : (string) $hash;
    }

    private function assertRuleViolation(string $message, \Closure $action): void
    {
        try {
            $action();
            self::fail('UserRuleViolationException erwartet.');
        } catch (UserRuleViolationException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertFalse($this->pdo()->inTransaction(), 'Transaktion zurückgerollt');
    }
}
