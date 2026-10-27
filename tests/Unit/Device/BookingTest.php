<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\Booking\BookingOutcome;
use FirefightingEquipment\Device\Booking\BookingReason;
use FirefightingEquipment\Device\Booking\BookingRequest;
use FirefightingEquipment\Device\Booking\ClientRef;
use FirefightingEquipment\Device\Booking\StoredBooking;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\InvalidDeviceValueException;
use FirefightingEquipment\Device\NotAppliedReason;
use FirefightingEquipment\Offline\ActionTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClientRef::class)]
#[CoversClass(BookingRequest::class)]
#[CoversClass(BookingOutcome::class)]
#[CoversClass(BookingReason::class)]
#[CoversClass(StoredBooking::class)]
final class BookingTest extends TestCase
{
    public function testClientRefIsStoredLowercase(): void
    {
        self::assertSame('3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f', ClientRef::fromString('3F2C8A1E-5B7D-4C9E-8F10-2A3B4C5D6E7F')->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidClientRefs(): iterable
    {
        yield 'leer' => [''];
        yield 'ohne Bindestriche' => ['3f2c8a1e5b7d4c9e8f102a3b4c5d6e7f'];
        yield 'Version 1' => ['3f2c8a1e-5b7d-1c9e-8f10-2a3b4c5d6e7f'];
        yield 'falsche Variante' => ['3f2c8a1e-5b7d-4c9e-cf10-2a3b4c5d6e7f'];
        yield 'mit Klammern' => ['{3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f}'];
        yield 'Zeilenumbruch' => ["3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f\n"];
        yield 'kein Hex' => ['3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7g'];
    }

    #[DataProvider('invalidClientRefs')]
    public function testInvalidClientRefIsRejected(string $value): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        ClientRef::fromString($value);
    }

    public function testHashCoversContentButNotConfirmation(): void
    {
        $change = new DeviceStateChange(5, DeviceStatus::Defective);
        $at = ActionTime::fromString('2026-10-27T08:00:00Z');
        $hash = BookingRequest::hash(1, 2, $change, 'Riss', $at);

        self::assertSame(32, strlen($hash));
        self::assertSame($hash, BookingRequest::hash(1, 2, $change, 'Riss', ActionTime::fromString('2026-10-27T08:00:00.400Z')));
        foreach ([
            BookingRequest::hash(2, 2, $change, 'Riss', $at),
            BookingRequest::hash(1, 3, $change, 'Riss', $at),
            BookingRequest::hash(1, 2, new DeviceStateChange(6, DeviceStatus::Defective), 'Riss', $at),
            BookingRequest::hash(1, 2, new DeviceStateChange(5, null), 'Riss', $at),
            BookingRequest::hash(1, 2, $change, null, $at),
            BookingRequest::hash(1, 2, $change, 'Riss', null),
            BookingRequest::hash(1, 2, $change, 'Riss', ActionTime::fromString('2026-10-27T08:00:01Z')),
        ] as $other) {
            self::assertNotSame($hash, $other);
        }
    }

    public function testOfflineBookingNeedsClientRef(): void
    {
        $this->expectException(\LogicException::class);
        new BookingRequest(null, ActionTime::fromString('2026-10-27T08:00:00Z'), false, '');
    }

    public function testOnlyOfflineBookingsCanBeStale(): void
    {
        $this->expectException(\LogicException::class);
        new BookingRequest(ClientRef::fromString('3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f'), null, true, '');
    }

    public function testStoredBookingMatchesOnlySameBooking(): void
    {
        $stored = new StoredBooking(1, 7, 9, 'hash', BookingOutcome::Applied, null, false, 3);

        self::assertTrue($stored->matches(7, 9, 'hash'));
        self::assertFalse($stored->matches(8, 9, 'hash'));
        self::assertFalse($stored->matches(7, 10, 'hash'));
        self::assertFalse($stored->matches(7, 9, 'other'));
    }

    public function testLabelsAndReasonsMatchNotAppliedReasons(): void
    {
        self::assertSame(
            ['applied' => 'übernommen', 'history_only' => 'nur Historie', 'not_applied' => 'nicht übernommen', 'unchanged' => 'keine Änderung'],
            array_combine(
                array_map(static fn (BookingOutcome $o): string => $o->value, BookingOutcome::cases()),
                array_map(static fn (BookingOutcome $o): string => $o->label(), BookingOutcome::cases()),
            ),
        );
        foreach (NotAppliedReason::cases() as $reason) {
            self::assertSame($reason->label(), BookingReason::notApplied($reason)->label());
        }
        self::assertSame('Anderen Stand überschrieben', BookingReason::Overwritten->label());
    }
}
