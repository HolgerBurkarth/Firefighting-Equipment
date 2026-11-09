<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Permission;

use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\DeviceVisibility;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Role\DefaultRoles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Wirksame Rechte über Claude (docs/MCP-KONZEPT.md, Abschnitt 2): Rolle ∩ Zugang, ohne Benutzer,
 * Einstellungen und Log-Mails, für jede Kombination aus Rolle und Zugang.
 */
#[CoversClass(Permissions::class)]
#[UsesClass(DeviceVisibility::class)]
#[UsesClass(ClaudeAccess::class)]
final class PermissionsForClaudeTest extends TestCase
{
    /**
     * @return iterable<string, array{Permissions, ClaudeAccess, array<string, string>, list<string>}>
     */
    public static function combinations(): iterable
    {
        $all = Permissions::all();
        $allAreas = ['dashboard' => 'read', 'search' => 'read', 'devices' => 'write', 'locations' => 'write', 'device_types' => 'write', 'due' => 'write', 'log' => 'write', 'maintenance' => 'write'];
        $allActions = array_values(array_filter(array_map(static fn (Action $a): string => $a->value, Action::cases()), static fn (string $a): bool => $a !== 'mail.log'));
        $read = array_map(static fn (): string => 'read', $allAreas);

        yield 'Admin, Ändern' => [$all, ClaudeAccess::Write, $allAreas, $allActions];
        yield 'Admin, Lesen' => [$all, ClaudeAccess::Read, $read, []];
        yield 'Admin, Aus' => [$all, ClaudeAccess::Off, [], []];

        $user = Permissions::fromRows(DefaultRoles::USER_AREAS, DefaultRoles::USER_ACTIONS);
        yield 'Benutzer, Ändern' => [$user, ClaudeAccess::Write, ['search' => 'read', 'due' => 'read'], ['book.move', 'book.status']];
        yield 'Benutzer, Lesen' => [$user, ClaudeAccess::Read, ['search' => 'read', 'due' => 'read'], []];

        $personnel = Permissions::fromRows(['users' => 'write', 'settings' => 'write', 'search' => 'read'], ['mail.log', 'book.move']);
        yield 'Personal: nie Benutzer und Einstellungen' => [$personnel, ClaudeAccess::Write, ['search' => 'read'], ['book.move']];

        $filtered = Permissions::fromRows(['devices' => 'write', 'locations' => 'write', 'search' => 'read'], ['csv.import', 'device.create'])
            ->ofRole(7, 1, DeviceVisibility::restricted([3], null));
        yield 'Datenfilter: F3 bleibt' => [$filtered, ClaudeAccess::Write, ['search' => 'read', 'devices' => 'write', 'locations' => 'read'], ['device.create']];
    }

    /**
     * @param array<string, string> $areas
     * @param list<string>          $actions
     */
    #[DataProvider('combinations')]
    public function testEffectiveRights(Permissions $role, ClaudeAccess $access, array $areas, array $actions): void
    {
        $effective = $role->forClaude($access);

        self::assertFalse($effective->isAll());
        self::assertEqualsCanonicalizing($areas, array_map(static fn (AreaLevel $l): string => $l->value, $effective->areas()));
        self::assertEqualsCanonicalizing($actions, array_map(static fn (Action $a): string => $a->value, $effective->actions()));
        self::assertFalse($effective->allows(Area::Users, AreaLevel::Read));
        self::assertFalse($effective->allows(Area::Settings, AreaLevel::Read));
        self::assertFalse($effective->can(Action::MailLog));
        self::assertSame($role->visibility(), $effective->visibility());
        self::assertSame($role->revision(), $effective->revision());
    }
}
