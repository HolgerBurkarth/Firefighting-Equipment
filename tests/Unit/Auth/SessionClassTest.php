<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Auth;

use FirefightingEquipment\Auth\AuthSession;
use FirefightingEquipment\Auth\SessionClass;
use FirefightingEquipment\Auth\SessionLifetime;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Role\DefaultRoles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionClass::class)]
#[UsesClass(AuthSession::class)]
#[UsesClass(Permissions::class)]
#[UsesClass(SessionLifetime::class)]
final class SessionClassTest extends TestCase
{
    public function testStandardRolesKeepTheirDurations(): void
    {
        // Vorgabe Schritt 1.3b: Admin 7/30 Tage, Benutzer 30/90 Tage wie bisher.
        $admin = SessionClass::forPermissions(Permissions::all());
        $user = SessionClass::forPermissions(Permissions::fromRows(DefaultRoles::USER_AREAS, DefaultRoles::USER_ACTIONS));

        self::assertSame([7 * 86400, 30 * 86400], [SessionLifetime::idleSeconds($admin), SessionLifetime::maxSeconds($admin)]);
        self::assertSame([30 * 86400, 90 * 86400], [SessionLifetime::idleSeconds($user), SessionLifetime::maxSeconds($user)]);
        self::assertSame(SessionClass::SuperAdmin, AuthSession::classOf(null));
    }

    /**
     * @return iterable<string, array{list<array{Area, AreaLevel}>, list<Action>, SessionClass}>
     */
    public static function roles(): iterable
    {
        yield 'keine Rechte' => [[], [], SessionClass::Long];
        yield 'nur ansehen' => [[[Area::Search, AreaLevel::Read], [Area::Devices, AreaLevel::Read], [Area::Due, AreaLevel::Read]], [], SessionClass::Long];
        yield 'ansehen, buchen, Log-Mails' => [[[Area::Search, AreaLevel::Read], [Area::Log, AreaLevel::Read]], [Action::BookMove, Action::BookStatus, Action::MailLog], SessionClass::Long];
        yield 'Benutzer ansehen' => [[[Area::Users, AreaLevel::Read]], [], SessionClass::Short];
        yield 'Einstellungen ansehen' => [[[Area::Settings, AreaLevel::Read]], [], SessionClass::Short];
        yield 'Termine ändern' => [[[Area::Due, AreaLevel::Write]], [], SessionClass::Short];
        yield 'Stammdaten bearbeiten' => [[[Area::Devices, AreaLevel::Write]], [Action::DeviceEdit], SessionClass::Short];
        yield 'Aktion ohne ihren Bereich wirkt nicht' => [[[Area::Devices, AreaLevel::Read]], [Action::DeviceEdit, Action::CsvImport], SessionClass::Long];
    }

    /**
     * @param list<array{Area, AreaLevel}> $areas
     * @param list<Action>                $actions
     */
    #[DataProvider('roles')]
    public function testClassFollowsRights(array $areas, array $actions, SessionClass $expected): void
    {
        self::assertSame($expected, SessionClass::forPermissions(Permissions::of($areas, $actions)));
    }
}
