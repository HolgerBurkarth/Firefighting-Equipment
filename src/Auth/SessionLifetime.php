<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Wie lange eine Sitzung gilt: Inaktivität (seit last_seen_at) und Höchstdauer (seit dem Anmelden),
 * je Klasse (SessionClass).
 */
final class SessionLifetime
{
    private const int HOUR = 3600;
    private const int DAY = 86400;

    /** last_seen_at wird höchstens so oft geschrieben. */
    public const int TOUCH_INTERVAL_SECONDS = 300;

    public static function idleSeconds(SessionClass $class): int
    {
        return match ($class) {
            SessionClass::Long => 30 * self::DAY,
            SessionClass::Short => 7 * self::DAY,
            SessionClass::SuperAdmin => 1 * self::HOUR,
        };
    }

    public static function maxSeconds(SessionClass $class): int
    {
        return match ($class) {
            SessionClass::Long => 90 * self::DAY,
            SessionClass::Short => 30 * self::DAY,
            SessionClass::SuperAdmin => 12 * self::HOUR,
        };
    }
}
