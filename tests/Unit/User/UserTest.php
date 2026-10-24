<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\User;

use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
#[CoversClass(UserRole::class)]
#[CoversClass(UserStatus::class)]
#[UsesClass(EmailAddress::class)]
#[UsesClass(PersonName::class)]
final class UserTest extends TestCase
{
    public function testEnumValuesMatchDatabaseChecks(): void
    {
        // Siehe chk_fe_users_role und chk_fe_users_status in migrations/0002_create_fe_users.sql.
        self::assertSame(['superadmin', 'admin', 'user'], array_column(UserRole::cases(), 'value'));
        self::assertSame(
            ['invited', 'unverified', 'pending', 'active', 'deleted'],
            array_column(UserStatus::cases(), 'value'),
        );
    }

    public function testGermanLabels(): void
    {
        self::assertSame(['Super-Admin', 'Admin', 'Benutzer'], array_map(static fn (UserRole $r) => $r->label(), UserRole::cases()));
        self::assertSame(
            ['eingeladen', 'unbestätigt', 'wartet auf Freischaltung', 'aktiv', 'gelöscht'],
            array_map(static fn (UserStatus $s) => $s->label(), UserStatus::cases()),
        );
    }

    public function testLabelPrefersDisplayNameThenNameThenEmail(): void
    {
        self::assertSame('Erika', self::user(name: 'Erika Musterfrau', displayName: 'Erika')->label());
        self::assertSame('Erika Musterfrau', self::user(name: 'Erika Musterfrau')->label());
        self::assertSame('erika@example.org', self::user()->label());
    }

    public function testDeletedUserLabel(): void
    {
        $user = self::user(status: UserStatus::Deleted, email: null);

        self::assertTrue($user->isDeleted());
        self::assertSame('Gelöschter Benutzer #7', $user->label());
    }

    /**
     * @return iterable<string, array{UserRole, UserStatus, bool, bool}>
     */
    public static function administrators(): iterable
    {
        yield 'aktiver Admin' => [UserRole::Admin, UserStatus::Active, false, true];
        yield 'aktiver Super-Admin' => [UserRole::SuperAdmin, UserStatus::Active, false, true];
        yield 'gesperrter Admin' => [UserRole::Admin, UserStatus::Active, true, false];
        yield 'eingeladener Admin' => [UserRole::Admin, UserStatus::Invited, false, false];
        yield 'wartender Admin' => [UserRole::Admin, UserStatus::Pending, false, false];
        yield 'gelöschter Admin' => [UserRole::Admin, UserStatus::Deleted, false, false];
        yield 'aktiver Benutzer' => [UserRole::User, UserStatus::Active, false, false];
    }

    #[DataProvider('administrators')]
    public function testIsActiveAdministrator(UserRole $role, UserStatus $status, bool $locked, bool $expected): void
    {
        $user = self::user(role: $role, status: $status, locked: $locked);

        self::assertSame($locked, $user->isLocked());
        self::assertSame($expected, $user->isActiveAdministrator());
    }

    private static function user(
        UserRole $role = UserRole::User,
        UserStatus $status = UserStatus::Active,
        ?string $email = 'erika@example.org',
        ?string $name = null,
        ?string $displayName = null,
        bool $locked = false,
    ): User {
        $now = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));

        return new User(
            7,
            $role === UserRole::SuperAdmin ? null : 1,
            $role,
            $status,
            $email === null ? null : EmailAddress::fromString($email),
            $name === null ? null : PersonName::fromString($name),
            $displayName === null ? null : PersonName::fromString($displayName),
            null,
            $locked ? $now : null,
            $status === UserStatus::Deleted ? $now : null,
            $now,
            $now,
        );
    }
}
