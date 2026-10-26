<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\InspectionWindow;
use FirefightingEquipment\Tests\Support\FixedClock;
use FirefightingEquipment\Time\LocalDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InspectionWindow::class)]
#[UsesClass(LocalDate::class)]
#[UsesClass(DeviceDate::class)]
final class InspectionWindowTest extends TestCase
{
    public function testDefaultIsThirtyDaysFromTodayInGermanTime(): void
    {
        $window = InspectionWindow::from(new FixedClock('2026-06-15T22:30:00Z'));

        self::assertSame(['2026-06-16', 30, '2026-07-16'], [$window->today, $window->days, $window->until]);
    }

    public function testDaysUntil(): void
    {
        $window = InspectionWindow::from(new FixedClock('2026-06-15T10:00:00Z'), 1);

        self::assertSame('2026-06-16', $window->until);
        self::assertSame(-1, $window->daysUntil(DeviceDate::fromDatabase('2026-06-14')));
        self::assertSame(0, $window->daysUntil(DeviceDate::fromDatabase('2026-06-15')));
        self::assertSame(31, $window->daysUntil(DeviceDate::fromDatabase('2026-07-16')));
    }

    public function testBounds(): void
    {
        $clock = new FixedClock('2026-06-15T10:00:00Z');

        self::assertSame('2026-06-16', InspectionWindow::from($clock, InspectionWindow::MIN_DAYS)->until);
        self::assertSame('2027-06-15', InspectionWindow::from($clock, InspectionWindow::MAX_DAYS)->until);
    }

    #[TestWith([0])]
    #[TestWith([366])]
    #[TestWith([-1])]
    public function testRejectsDaysOutOfRange(int $days): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InspectionWindow::from(new FixedClock('2026-06-15T10:00:00Z'), $days);
    }
}
