<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Roles;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\ActionGroup;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Permission\Restriction;
use FirefightingEquipment\Role\Role;
use FirefightingEquipment\Role\RoleUsage;
use FirefightingEquipment\Role\SystemRole;

/**
 * JSON der Rollen und des Rechte-Katalogs für den Super-Admin. Zeiten in UTC.
 */
final class RoleJson
{
    private function __construct()
    {
    }

    /**
     * Rolle mit Bereichen (Objekt Bereich → Stufe, Reihenfolge des Katalogs), Aktionen (Katalog),
     * Datenfiltern (null = alle) und Nutzung. Die Rolle Admin erscheint mit allen Rechten des
     * Katalogs (all_rights). Werte, die der Katalog nicht kennt, fehlen.
     *
     * @return array<string, mixed>
     */
    public static function role(Role $role, RoleUsage $usage): array
    {
        $isAdmin = $role->systemRole === SystemRole::Admin;
        $areas = [];
        foreach (Area::cases() as $area) {
            $level = $isAdmin ? $area->maxLevel() : AreaLevel::tryFrom($role->areas[$area->value] ?? '');
            if ($level !== null) {
                $areas[$area->value] = $level->value;
            }
        }
        $actions = array_values(array_filter(
            Action::cases(),
            static fn (Action $action): bool => $isAdmin || in_array($action->value, $role->actions, true),
        ));

        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'system_key' => $role->systemRole?->value,
            'all_rights' => $isAdmin,
            'editable' => !$isAdmin,
            'deletable' => $role->systemRole === null,
            'areas' => (object) $areas,
            'actions' => array_map(static fn (Action $action): string => $action->value, $actions),
            'locations' => $role->filterLocations ? $role->locationIds : null,
            'device_types' => $role->filterDeviceTypes ? $role->deviceTypeIds : null,
            'restrictions' => !$isAdmin && $role->restrictSingleUse ? [Restriction::SingleUseUsedOnly->value] : [],
            'version' => $role->version,
            'created_at' => AuthJson::time($role->createdAt),
            'updated_at' => AuthJson::time($role->updatedAt),
            'usage' => ['accounts' => $usage->accounts, 'invitation_codes' => $usage->openInvitationCodes],
        ];
    }

    /**
     * Rechte-Katalog für den Editor: Bereiche mit möglichen Stufen (und den Stufen, die es mit
     * Datenfilter gibt, F3), Stufen, Aktionen in Gruppen mit dem Bereich, den sie brauchen.
     *
     * @return array<string, mixed>
     */
    public static function catalog(): array
    {
        $levels = static fn (?AreaLevel $max): array => $max === null ? [] : array_values(array_map(
            static fn (AreaLevel $level): string => $level->value,
            array_filter(AreaLevel::cases(), static fn (AreaLevel $level): bool => $max->includes($level)),
        ));

        $groups = [];
        foreach (ActionGroup::cases() as $group) {
            $groups[] = [
                'label' => $group->label(),
                'actions' => array_values(array_map(
                    static function (Action $action): array {
                        $requires = $action->requires();

                        return [
                            'key' => $action->value,
                            'label' => $action->label(),
                            'requires' => $requires === null ? null : ['area' => $requires[0]->value, 'level' => $requires[1]->value],
                            'with_filter' => !Permissions::excludedActionWithFilter($action),
                        ];
                    },
                    array_filter(Action::cases(), static fn (Action $action): bool => $action->group() === $group),
                )),
            ];
        }

        return [
            'areas' => array_map(
                static function (Area $area) use ($levels): array {
                    $excluded = Permissions::excludedWithFilter($area);

                    return [
                        'key' => $area->value,
                        'label' => $area->label(),
                        'hint' => $area->hint(),
                        'levels' => $levels($area->maxLevel()),
                        'levels_with_filter' => $levels(match ($excluded) {
                            null => $area->maxLevel(),
                            AreaLevel::Read => null,
                            AreaLevel::Write => AreaLevel::Read,
                        }),
                    ];
                },
                Area::cases(),
            ),
            'levels' => array_map(
                static fn (AreaLevel $level): array => ['key' => $level->value, 'label' => $level->label()],
                AreaLevel::cases(),
            ),
            'action_groups' => $groups,
            'restrictions' => array_map(
                static fn (Restriction $restriction): array => [
                    'key' => $restriction->value,
                    'label' => $restriction->label(),
                    'hint' => $restriction->hint(),
                ],
                Restriction::cases(),
            ),
        ];
    }
}
