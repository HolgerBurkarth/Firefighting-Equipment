<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

/**
 * Einschränkungen einer Rolle (Phase 2, Plan Abschnitt 1; „3 · Einschränkungen“ im Editor der
 * Rollen). Eine Einschränkung nimmt einer Rolle etwas, das ihre Rechte sonst erlauben würden; sie
 * steht in permissions.restrictions von auth/me. Die Rolle Admin hat nie eine (CHECK
 * chk_fe_roles_admin).
 */
enum Restriction: string
{
    /** Bei Einmalgeräten nur „Benutzt melden“: kein Umbuchen und kein Status setzen (fe_roles.restrict_single_use). */
    case SingleUseUsedOnly = 'single_use_used_only';

    public function label(): string
    {
        return match ($this) {
            self::SingleUseUsedOnly => 'Bei Einmalgeräten nur „Benutzt melden“',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::SingleUseUsedOnly => 'Einmalgeräte darf diese Rolle nicht umbuchen und nicht auf einen anderen Status setzen, nur als benutzt melden.',
        };
    }
}
