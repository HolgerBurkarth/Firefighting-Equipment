<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

/**
 * Rolle eines Kontos (fe_users.role).
 */
enum UserRole: string
{
    /** Verwaltet Mandanten; hat keine Stadt. */
    case SuperAdmin = 'superadmin';
    /** Geräte, Standorte und Benutzer der eigenen Stadt. */
    case Admin = 'admin';
    /** Bucht um, setzt Status. */
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super-Admin',
            self::Admin => 'Admin',
            self::User => 'Benutzer',
        };
    }
}
