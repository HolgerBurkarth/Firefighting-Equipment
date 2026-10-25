<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserAdministration;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserRuleViolationException;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * UserRepository: approve, reject, changeDisplayName, listByTenant mit Filter; UserAdministration:
 * Stadt-Bezug, Selbstschutz und die Regeln des Repositorys.
 */
#[CoversClass(UserRepository::class)]
#[CoversClass(UserAdministration::class)]
final class UserAdministrationRepositoryTest extends AuthTestCase
{
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo());
    }

    public function testApprove(): void
    {
        $id = $this->registeredAccount('immendingen', 'max@example.org', 'pending');

        $user = $this->users->approve($id, PersonName::fromString('Max'), UserRole::Admin);

        self::assertSame(UserStatus::Active, $user->status);
        self::assertSame(UserRole::Admin, $user->role);
        self::assertSame('Max', $user->displayName?->value);
    }

    public function testApproveRefusesSuperAdminRoleAndOtherStatus(): void
    {
        $pending = $this->registeredAccount('immendingen', 'max@example.org', 'pending');
        $unverified = $this->registeredAccount('immendingen', 'neu@example.org', 'unverified');

        $this->assertViolation(fn () => $this->users->approve($pending, PersonName::fromString('Max'), UserRole::SuperAdmin));
        $this->assertViolation(fn () => $this->users->approve($unverified, PersonName::fromString('Max'), UserRole::User));
        self::assertSame(UserStatus::Pending, $this->users->findById($pending)?->status);
    }

    public function testRejectReturnsDataBeforeAnonymizing(): void
    {
        $id = $this->registeredAccount('immendingen', 'max@example.org', 'unverified');

        $before = $this->users->reject($id);

        self::assertSame('max@example.org', $before->email?->value);
        self::assertSame(UserStatus::Deleted, $this->users->findById($id)?->status);
        $this->assertViolation(fn () => $this->users->reject($id));
        $this->assertViolation(fn () => $this->users->reject($this->account('immendingen', 'aktiv@example.org')));
    }

    public function testChangeDisplayName(): void
    {
        $id = $this->account('immendingen', 'user@example.org');

        self::assertTrue($this->users->changeDisplayName($id, PersonName::fromString('Neu')));
        self::assertFalse($this->users->changeDisplayName($id, PersonName::fromString('Neu')));
        self::assertSame('Neu', $this->users->findById($id)?->displayName?->value);

        $this->users->anonymize($id);
        $this->assertViolation(fn () => $this->users->changeDisplayName($id, PersonName::fromString('X')));
    }

    public function testListByTenantWithStatusFilter(): void
    {
        $active = $this->account('immendingen', 'user@example.org');
        $pending = $this->registeredAccount('immendingen', 'max@example.org', 'pending');
        $this->registeredAccount('tuttlingen', 'max@example.org', 'pending');

        $ids = static fn (array $list): array => array_map(static fn (User $u): int => $u->id, $list);
        self::assertSame([$pending], $ids($this->users->listByTenant($this->tenant('immendingen'), UserStatus::Pending)));
        self::assertContains($active, $ids($this->users->listByTenant($this->tenant('immendingen'))));
    }

    public function testAdministrationIsScopedToTenant(): void
    {
        $actor = $this->admin('immendingen', 'admin@example.org');
        $foreign = $this->account('tuttlingen', 'user@example.org');
        $admin = new UserAdministration($this->users, $this->tenant('immendingen'), $actor);

        $this->expectException(UserNotFoundException::class);
        $admin->lock($foreign);
    }

    /**
     * Wird der handelnde Admin zwischen Sitzungsprüfung und Aktion gesperrt, schützt das Repository
     * den nun letzten aktiven Admin weiterhin.
     */
    public function testLastAdminRuleOfRepositoryStillApplies(): void
    {
        $actor = $this->admin('immendingen', 'admin@example.org');
        $other = $this->account('immendingen', 'admin2@example.org', ['role' => 'admin']);
        $this->users->lock($actor->id);
        $admin = new UserAdministration($this->users, $this->tenant('immendingen'), $actor);

        $this->assertViolation(fn () => $admin->lock($other));
        $this->assertViolation(fn () => $admin->changeRole($other, UserRole::User));
        $this->assertViolation(fn () => $admin->delete($other));
        self::assertTrue($this->users->findById($other)?->isActiveAdministrator());
    }

    private function admin(string $slug, string $email): User
    {
        return $this->users->findById($this->account($slug, $email, ['role' => 'admin'])) ?? self::fail('Konto fehlt.');
    }

    private function assertViolation(\Closure $action): void
    {
        try {
            $action();
            self::fail('UserRuleViolationException erwartet.');
        } catch (UserRuleViolationException) {
            self::assertFalse($this->pdo()->inTransaction());
        }
    }
}
