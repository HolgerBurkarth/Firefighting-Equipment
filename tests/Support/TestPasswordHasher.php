<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

use FirefightingEquipment\User\Password\PasswordHasher;

/**
 * Argon2id mit minimalen Parametern, damit Tests nicht pro Hash eine Viertelsekunde brauchen.
 * Nur für Tests; Produktion verwendet immer die Standardparameter. Tests zu Laufzeit, Hash-Format
 * und needsRehash mit Standardparametern verwenden bewusst new PasswordHasher().
 */
final class TestPasswordHasher
{
    public const int MEMORY_COST = 1024;
    public const int TIME_COST = 1;
    public const int THREADS = 1;

    private static ?PasswordHasher $instance = null;

    /**
     * Gemeinsame Instanz (der Dummy-Hash wird nur einmal berechnet).
     */
    public static function fast(): PasswordHasher
    {
        return self::$instance ??= new PasswordHasher(self::MEMORY_COST, self::TIME_COST, self::THREADS);
    }
}
