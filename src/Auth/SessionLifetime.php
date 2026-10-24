<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

use FirefightingEquipment\User\UserRole;

/**
 * Wie lange eine Sitzung gilt: Inaktivität (seit last_seen_at) und Höchstdauer (seit dem Anmelden).
 */
final class SessionLifetime
{
    private const int HOUR = 3600;
    private const int DAY = 86400;

    /** last_seen_at wird höchstens so oft geschrieben. */
    public const int TOUCH_INTERVAL_SECONDS = 300;

    public static function idleSeconds(UserRole $role): int
    {
        return match ($role) {
            UserRole::User => 30 * self::DAY,
            UserRole::Admin => 7 * self::DAY,
            UserRole::SuperAdmin => 1 * self::HOUR,
        };
    }

    public static function maxSeconds(UserRole $role): int
    {
        return match ($role) {
            UserRole::User => 90 * self::DAY,
            UserRole::Admin => 30 * self::DAY,
            UserRole::SuperAdmin => 12 * self::HOUR,
        };
    }
}
