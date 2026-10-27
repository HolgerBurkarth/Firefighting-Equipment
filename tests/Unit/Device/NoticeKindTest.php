<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\Booking\BookingReason;
use FirefightingEquipment\Device\Notice\NoticeChange;
use FirefightingEquipment\Device\Notice\NoticeKind;
use FirefightingEquipment\Device\Notice\NoticeStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoticeKind::class)]
#[CoversClass(NoticeStatus::class)]
#[CoversClass(NoticeChange::class)]
final class NoticeKindTest extends TestCase
{
    public function testKindForResult(): void
    {
        self::assertSame(NoticeKind::Overwritten, NoticeKind::forResult(BookingReason::Overwritten, false));
        self::assertSame(NoticeKind::Overwritten, NoticeKind::forResult(BookingReason::Overwritten, true));
        self::assertSame(NoticeKind::HistoryOnly, NoticeKind::forResult(BookingReason::Older, false));
        self::assertSame(NoticeKind::NotAppliedRetired, NoticeKind::forResult(BookingReason::Retired, false));
        self::assertSame(NoticeKind::NotAppliedLocationLocked, NoticeKind::forResult(BookingReason::LocationLocked, true));
        self::assertSame(NoticeKind::StaleConfirmed, NoticeKind::forResult(null, true));
        self::assertNull(NoticeKind::forResult(null, false));
    }

    public function testValuesMatchSchemaAndHaveGermanLabels(): void
    {
        self::assertSame(
            ['overwritten', 'history_only', 'not_applied_retired', 'not_applied_location_locked', 'stale_confirmed'],
            array_map(static fn (NoticeKind $k): string => $k->value, NoticeKind::cases()),
        );
        foreach (NoticeKind::cases() as $kind) {
            self::assertNotSame('', $kind->label());
            self::assertNotSame('', $kind->subjectReason());
        }
        self::assertSame(['offen', 'erledigt'], array_map(static fn (NoticeStatus $s): string => $s->label(), NoticeStatus::cases()));
        self::assertSame(NoticeStatus::Resolved, NoticeChange::Resolve->status());
        self::assertSame(NoticeStatus::Open, NoticeChange::Reopen->status());
        self::assertSame(['Erledigt', 'Wieder geöffnet'], array_map(static fn (NoticeChange $c): string => $c->label(), NoticeChange::cases()));
    }
}
