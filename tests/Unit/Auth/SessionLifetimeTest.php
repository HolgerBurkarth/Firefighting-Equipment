<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Auth;

use FirefightingEquipment\Auth\SessionClass;
use FirefightingEquipment\Auth\SessionLifetime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionLifetime::class)]
final class SessionLifetimeTest extends TestCase
{
    public function testLimitsPerClassAsDocumented(): void
    {
        // Lang = bisher user, kurz = bisher admin (CLAUDE.md, Sitzungsdauer).
        self::assertSame(30 * 86400, SessionLifetime::idleSeconds(SessionClass::Long));
        self::assertSame(90 * 86400, SessionLifetime::maxSeconds(SessionClass::Long));
        self::assertSame(7 * 86400, SessionLifetime::idleSeconds(SessionClass::Short));
        self::assertSame(30 * 86400, SessionLifetime::maxSeconds(SessionClass::Short));
        self::assertSame(3600, SessionLifetime::idleSeconds(SessionClass::SuperAdmin));
        self::assertSame(12 * 3600, SessionLifetime::maxSeconds(SessionClass::SuperAdmin));
    }

    public function testMaxDurationFitsDatabaseCheck(): void
    {
        // chk_fe_sessions_expires: höchstens 90 Tage.
        foreach (SessionClass::cases() as $class) {
            self::assertLessThanOrEqual(90 * 86400, SessionLifetime::maxSeconds($class));
            self::assertLessThan(SessionLifetime::maxSeconds($class), SessionLifetime::idleSeconds($class));
            self::assertLessThan(SessionLifetime::idleSeconds($class), SessionLifetime::TOUCH_INTERVAL_SECONDS);
        }
    }
}
