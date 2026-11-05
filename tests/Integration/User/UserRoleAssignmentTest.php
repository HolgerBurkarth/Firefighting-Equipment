<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Plan Phase 1, Schritt 1.2: Jeder Schreibweg von UserRepository setzt Kontoart (role) und Rolle
 * (role_id) gemeinsam: admin ↔ Rolle Admin, user ↔ Rolle Benutzer der eigenen Stadt; Super-Admins
 * ohne Rolle. Zusätzlich prüft DomainTestCase die Regel nach jedem Test über alle Zeilen.
 */
#[CoversClass(UserRepository::class)]
final class UserRoleAssignmentTest extends AuthTestCase
{
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo());
    }

    public function testRegisteredAccountGetsUserRole(): void
    {
        $admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        [, $code] = new InvitationCodeRepository($this->pdo())->create($this->tenant('immendingen'), $admin, null, 7, 2);

        $registration = $this->users->register(
            $this->tenant('immendingen'),
            $code,
            EmailAddress::fromString('max@example.org'),
            PersonName::fromString('Max Mustermann'),
            self::PASSWORD_HASH,
        );

        self::assertSame($this->roleId('immendingen', 'user'), $this->roleIdOf($registration->user->id));
    }

    public function testApproveSetsRoleOfAccountKind(): void
    {
        $asUser = $this->registeredAccount('immendingen', 'benutzer@example.org', 'pending');
        $asAdmin = $this->registeredAccount('immendingen', 'admin@example.org', 'pending');

        $this->users->approve($asUser, PersonName::fromString('Max'), UserRole::User);
        $this->users->approve($asAdmin, PersonName::fromString('Erika'), UserRole::Admin);

        self::assertSame($this->roleId('immendingen', 'user'), $this->roleIdOf($asUser));
        self::assertSame($this->roleId('immendingen', 'admin'), $this->roleIdOf($asAdmin));
    }

    public function testChangeRoleMovesBetweenStandardRoles(): void
    {
        $this->account('immendingen', 'chef@example.org', ['role' => 'admin']);
        $id = $this->account('immendingen', 'max@example.org');

        self::assertTrue($this->users->changeRole($id, UserRole::Admin));
        self::assertSame($this->roleId('immendingen', 'admin'), $this->roleIdOf($id));

        self::assertTrue($this->users->changeRole($id, UserRole::User));
        self::assertSame($this->roleId('immendingen', 'user'), $this->roleIdOf($id));
    }

    public function testInvitedAdminsGetAdminRoleOfTheirTenant(): void
    {
        $created = $this->users->createTenantWithInvitedAdmin(
            TenantSlug::fromString('geisingen'),
            TenantName::fromString('Feuerwehr Geisingen'),
            EmailAddress::fromString('kommandant@example.org'),
        );
        $invited = $this->users->inviteAdmin($this->tenant('tuttlingen'), EmailAddress::fromString('neu@example.org'));

        self::assertSame($this->roleId('geisingen', 'admin'), $this->roleIdOf($created->user->id));
        self::assertSame($this->roleId('tuttlingen', 'admin'), $this->roleIdOf($invited->user->id));

        self::assertTrue($this->users->acceptInvitation($invited->user->id, PersonName::fromString('Neue Admin'), self::PASSWORD_HASH, $invited->token->hash()));
        self::assertSame($this->roleId('tuttlingen', 'admin'), $this->roleIdOf($invited->user->id));
    }

    public function testAnonymizedAccountKeepsItsRole(): void
    {
        $this->account('immendingen', 'chef@example.org', ['role' => 'admin']);
        $id = $this->account('immendingen', 'max@example.org');

        self::assertTrue($this->users->anonymize($id));

        self::assertSame($this->roleId('immendingen', 'user'), $this->roleIdOf($id));
    }

    public function testSuperAdminHasNoRole(): void
    {
        $user = $this->users->createSuperAdmin(EmailAddress::fromString('service@example.org'), PersonName::fromString('Service'), self::PASSWORD_HASH);

        self::assertNull($this->roleIdOf($user->id));
    }

    private function roleId(string $slug, string $systemKey): int
    {
        return $this->systemRoleId($this->tenant($slug)->id, $systemKey) ?? throw new \LogicException('Standard-Rolle fehlt.');
    }

    private function roleIdOf(int $userId): ?int
    {
        $value = $this->pdo()->query('SELECT role_id FROM fe_users WHERE id = ' . $userId)->fetchColumn();

        return $value === null ? null : (int) $value;
    }
}
