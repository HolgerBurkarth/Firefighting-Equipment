<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Booking;

use FirefightingEquipment\Device\NotAppliedReason;

/**
 * Grund eines besonderen Ergebnisses einer Offline-Buchung. Werte wie
 * chk_fe_device_bookings_outcome; die Gründe für „nicht übernommen“ wie NotAppliedReason.
 */
enum BookingReason: string
{
    /** Übernommen; ein zwischenzeitlich gebuchter, älterer Stand wurde überschrieben */
    case Overwritten = 'overwritten';
    case Older = 'older';
    case Retired = 'retired';
    case LocationLocked = 'location_locked';

    public static function notApplied(NotAppliedReason $reason): self
    {
        return self::from($reason->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Overwritten => 'Anderen Stand überschrieben',
            self::Older => NotAppliedReason::Older->label(),
            self::Retired => NotAppliedReason::Retired->label(),
            self::LocationLocked => NotAppliedReason::LocationLocked->label(),
        };
    }
}
