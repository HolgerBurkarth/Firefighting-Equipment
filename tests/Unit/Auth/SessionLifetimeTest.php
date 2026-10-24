<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Auth;

use FirefightingEquipment\Auth\SessionLifetime;
use FirefightingEquipment\User\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionLifetime::class)]
final class SessionLifetimeTest extends TestCase
{
    public function testLimitsPerRoleAsDocumented(): void
    {
        self::assertSame(30 * 86400, SessionLifetime::idleSeconds(UserRole::User));
        self::assertSame(90 * 86400, SessionLifetime::maxSeconds(UserRole::User));
        self::assertSame(7 * 86400, SessionLifetime::idleSeconds(UserRole::Admin));
        self::assertSame(30 * 86400, SessionLifetime::maxSeconds(UserRole::Admin));
        self::assertSame(3600, SessionLifetime::idleSeconds(UserRole::SuperAdmin));
        self::assertSame(12 * 3600, SessionLifetime::maxSeconds(UserRole::SuperAdmin));
    }

    public function testMaxDurationFitsDatabaseCheck(): void
    {
        // chk_fe_sessions_expires: höchstens 90 Tage.
        foreach (UserRole::cases() as $role) {
            self::assertLessThanOrEqual(90 * 86400, SessionLifetime::maxSeconds($role));
            self::assertLessThan(SessionLifetime::maxSeconds($role), SessionLifetime::idleSeconds($role));
            self::assertLessThan(SessionLifetime::idleSeconds($role), SessionLifetime::TOUCH_INTERVAL_SECONDS);
        }
    }
}
