<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Kennzeichen der Standard-Rollen jeder Stadt (fe_roles.system_key). Rollen ohne Kennzeichen legt
 * der Super-Admin an.
 */
enum SystemRole: string
{
    /** Alle Rechte, auch künftige; nicht änderbar, ohne Datenfilter. */
    case Admin = 'admin';
    /** Rechte des bisherigen Kontos "user"; änderbar. */
    case User = 'user';
}
