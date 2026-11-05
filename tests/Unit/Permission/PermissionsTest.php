<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Permission;

use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\DeviceVisibility;
use FirefightingEquipment\Permission\FieldRule;
use FirefightingEquipment\Permission\Need;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Permission\Requirement;
use FirefightingEquipment\Role\DefaultRoles;
use FirefightingEquipment\Role\Role;
use FirefightingEquipment\Role\SystemRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Permissions::class)]
#[CoversClass(Requirement::class)]
#[CoversClass(Need::class)]
#[CoversClass(FieldRule::class)]
#[CoversClass(Area::class)]
#[CoversClass(AreaLevel::class)]
#[CoversClass(Action::class)]
#[UsesClass(Role::class)]
#[UsesClass(DeviceVisibility::class)]
final class PermissionsTest extends TestCase
{
    public function testCatalogValuesFitDatabaseFormat(): void
    {
        foreach (Area::cases() as $area) {
            self::assertMatchesRegularExpression('/^[a-z_]{1,30}\z/', $area->value);
            self::assertNotSame('', $area->label());
        }
        foreach (Action::cases() as $action) {
            self::assertMatchesRegularExpression('/^[a-z_.]{1,40}\z/', $action->value);
            self::assertNotSame('', $action->label());
            $required = $action->requires();
            if ($required !== null) {
                self::assertTrue($required[0]->maxLevel()->includes($required[1]), $action->value);
            }
        }
    }

    public function testDefaultUserRoleUsesOnlyCatalogValues(): void
    {
        foreach (DefaultRoles::USER_AREAS as $area => $level) {
            self::assertNotNull(Area::tryFrom($area), $area);
            self::assertNotNull(AreaLevel::tryFrom($level), $level);
        }
        foreach (DefaultRoles::USER_ACTIONS as $action) {
            self::assertNotNull(Action::tryFrom($action), $action);
        }
    }

    public function testWriteIncludesRead(): void
    {
        self::assertTrue(AreaLevel::Write->includes(AreaLevel::Read));
        self::assertTrue(AreaLevel::Write->includes(AreaLevel::Write));
        self::assertTrue(AreaLevel::Read->includes(AreaLevel::Read));
        self::assertFalse(AreaLevel::Read->includes(AreaLevel::Write));
    }

    public function testAdminRoleHasAllRights(): void
    {
        $admin = Permissions::fromRole(self::role(SystemRole::Admin, [], []));

        self::assertTrue($admin->isAll());
        foreach (Area::cases() as $area) {
            self::assertSame($area->maxLevel(), $admin->level($area));
        }
        foreach (Action::cases() as $action) {
            self::assertTrue($admin->can($action));
        }
    }

    public function testUserRoleHasRightsOfFormerUser(): void
    {
        $user = Permissions::fromRole(self::role(SystemRole::User, DefaultRoles::USER_AREAS, DefaultRoles::USER_ACTIONS));

        self::assertFalse($user->isAll());
        self::assertTrue($user->allows(Area::Search, AreaLevel::Read));
        self::assertTrue($user->allows(Area::Due, AreaLevel::Read));
        self::assertFalse($user->allows(Area::Due, AreaLevel::Write));
        self::assertFalse($user->allows(Area::Devices, AreaLevel::Read));
        self::assertTrue($user->can(Action::BookMove));
        self::assertTrue($user->can(Action::BookStatus));
        self::assertFalse($user->can(Action::MailLog));
        self::assertFalse($user->can(Action::DeviceEdit));
    }

    public function testActionNeedsItsArea(): void
    {
        $withoutArea = Permissions::fromRows([], ['book.move', 'device.edit', 'mail.log']);
        self::assertFalse($withoutArea->can(Action::BookMove));
        self::assertFalse($withoutArea->can(Action::DeviceEdit));
        self::assertTrue($withoutArea->can(Action::MailLog), 'braucht keinen Bereich');

        $readOnly = Permissions::fromRows(['devices' => 'read'], ['device.edit']);
        self::assertFalse($readOnly->can(Action::DeviceEdit), 'Stammdaten brauchen Ausrüstungsliste: Ändern');
    }

    public function testUnknownValuesGiveNoRight(): void
    {
        $permissions = Permissions::fromRows(['maintenance' => 'write', 'search' => 'admin'], ['lend', 'book.used']);

        self::assertNull($permissions->level(Area::Search));
        self::assertSame(Permissions::none()->level(Area::Search), $permissions->level(Area::Search));
    }

    public function testRequirementAlternativesAndCombinations(): void
    {
        $see = Requirement::anyOf(Requirement::area(Area::Search, AreaLevel::Read), Requirement::area(Area::Devices, AreaLevel::Read));
        $bulkDue = Requirement::allOf(Requirement::action(Action::DevicesBulk), Requirement::area(Area::Due, AreaLevel::Write));

        self::assertSame('search:read | devices:read', $see->describe());
        self::assertSame('devices.bulk & due:write', $bulkDue->describe());
        self::assertSame(
            'search:read & due:write | devices:read & due:write',
            Requirement::allOf($see, Requirement::area(Area::Due, AreaLevel::Write))->describe(),
        );

        self::assertTrue($see->allows(Permissions::fromRows(['devices' => 'write'], []), []));
        self::assertFalse($see->allows(Permissions::fromRows(['due' => 'write'], []), []));
        self::assertTrue($bulkDue->allows(Permissions::fromRows(['devices' => 'write', 'due' => 'write'], ['devices.bulk']), []));
        self::assertFalse($bulkDue->allows(Permissions::fromRows(['devices' => 'write', 'due' => 'read'], ['devices.bulk']), []));
        self::assertFalse($bulkDue->allows(Permissions::none(), []));
        self::assertTrue($bulkDue->allows(Permissions::all(), []));
    }

    public function testFieldRulesApplyToPresentFieldsAndListEntries(): void
    {
        $book = Requirement::anyOf(Requirement::action(Action::BookMove), Requirement::action(Action::BookStatus))
            ->withFieldRule('location_id', Action::BookMove)
            ->withFieldRule('status', Action::BookStatus);
        $mover = Permissions::fromRows(['search' => 'read'], ['book.move']);

        self::assertTrue($book->allows($mover, ['location_id' => 3, 'comment' => 'x']));
        self::assertFalse($book->allows($mover, ['status' => 'ready']));
        self::assertFalse($book->allows($mover, ['status' => null]), 'auch null zählt als angegeben');

        $bulk = Requirement::action(Action::DevicesBulk)->withFieldRule('status', Action::BookStatus, 'devices');
        $bulker = Permissions::fromRows(['devices' => 'write', 'search' => 'read'], ['devices.bulk', 'book.move']);
        self::assertTrue($bulk->allows($bulker, ['devices' => [['id' => 1, 'location_id' => 2]]]));
        self::assertFalse($bulk->allows($bulker, ['devices' => [['id' => 1], ['id' => 2, 'status' => 'ready']]]));
        self::assertTrue($bulk->allows($bulker, ['devices' => 'kaputt']), 'ungültiger Body: das prüft der Handler (400)');
        self::assertSame('devices.bulk; Felder: devices[].status → book.status', $bulk->describe());
    }

    public function testReadOnlyAreasRejectWrite(): void
    {
        $this->expectException(\LogicException::class);
        Need::area(Area::Dashboard, AreaLevel::Write);
    }

    /** F5 (Schritt 1.6): wer eine Rolle zuweist, braucht alle ihre wirksamen Rechte. */
    public function testCoversComparesEffectiveRights(): void
    {
        $manager = Permissions::of([[Area::Users, AreaLevel::Write], [Area::Search, AreaLevel::Read], [Area::Due, AreaLevel::Read]], [Action::BookMove, Action::BookStatus]);
        $user = Permissions::fromRows(DefaultRoles::USER_AREAS, DefaultRoles::USER_ACTIONS);

        self::assertTrue(Permissions::all()->covers($manager));
        self::assertTrue($manager->covers($user));
        self::assertTrue($manager->covers($manager));
        self::assertFalse($user->covers($manager), 'users fehlt');
        self::assertFalse($manager->covers(Permissions::all()), 'nur Admin gibt Admin');
        self::assertFalse($manager->covers(Permissions::of([[Area::Due, AreaLevel::Write]], [])), 'Stufe');
        self::assertFalse($manager->covers(Permissions::of([[Area::Search, AreaLevel::Read]], [Action::MailLog])), 'Aktion');
        self::assertTrue($manager->covers(Permissions::of([], [Action::DeviceEdit])), 'Aktion ohne ihren Bereich wirkt nicht');

        $allCatalog = Permissions::of(
            array_map(static fn (Area $area): array => [$area, $area->maxLevel()], Area::cases()),
            Action::cases(),
        );
        self::assertFalse($allCatalog->covers(Permissions::all()), 'jedes Recht des Katalogs ist noch nicht Admin');
        self::assertTrue($allCatalog->covers($manager));
    }

    public function testCoversNeedsFilterWithinOwn(): void
    {
        $plain = Permissions::of([[Area::Search, AreaLevel::Read]], []);
        $hlf = $plain->ofRole(2, 1, DeviceVisibility::restricted([10, 11], null));
        $hlfOnly = $plain->ofRole(3, 1, DeviceVisibility::restricted([10], null));
        $lager = $plain->ofRole(4, 1, DeviceVisibility::restricted([20], null));
        $type = $plain->ofRole(5, 1, DeviceVisibility::restricted(null, [7]));

        self::assertTrue($plain->covers($hlf));
        self::assertTrue($hlf->covers($hlfOnly));
        self::assertFalse($hlfOnly->covers($hlf));
        self::assertFalse($hlf->covers($lager));
        self::assertFalse($hlf->covers($plain), 'ohne Filter sieht mehr');
        self::assertFalse($type->covers($hlf), 'Typfilter ohne Standortfilter');
        self::assertTrue(DeviceVisibility::restricted([10, 11], [7])->includes(DeviceVisibility::restricted([11], [7])));
        self::assertFalse(DeviceVisibility::restricted([10, 11], [7])->includes(DeviceVisibility::restricted([11], null)));
        self::assertTrue(DeviceVisibility::all()->includes(DeviceVisibility::none()));
    }

    public function testFromRoleWithFilterCarriesFilterOfRole(): void
    {
        $now = new \DateTimeImmutable('2026-11-05 12:00:00', new \DateTimeZone('UTC'));
        $role = new Role(9, 1, null, 'HLF', null, false, true, false, ['users' => 'write', 'search' => 'read'], [], [10], [], 3, $now, $now);

        $permissions = Permissions::fromRoleWithFilter($role);

        self::assertTrue($permissions->isFiltered());
        self::assertFalse($permissions->allows(Area::Users, AreaLevel::Read), 'F3');
        self::assertSame('9:3', $permissions->revision());
        self::assertTrue(Permissions::fromRoleWithFilter(self::role(SystemRole::Admin, [], []))->isAll());
    }

    /**
     * @param array<string, 'read'|'write'> $areas
     * @param list<string>                  $actions
     */
    private static function role(SystemRole $systemRole, array $areas, array $actions): Role
    {
        $now = new \DateTimeImmutable('2026-11-05 12:00:00', new \DateTimeZone('UTC'));

        return new Role(1, 1, $systemRole, 'Rolle', null, false, false, false, $areas, $actions, [], [], 1, $now, $now);
    }
}
