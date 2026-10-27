<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Booking;

/**
 * Ergebnis einer Buchung (Umbuchen/Status) für den Kameraden. Werte wie
 * chk_fe_device_bookings_outcome.
 */
enum BookingOutcome: string
{
    /** Standort und/oder Status geändert */
    case Applied = 'applied';
    /** Nicht übernommen, weil ein neuerer Stand vorliegt; nur in der Historie */
    case HistoryOnly = 'history_only';
    /** Nicht übernommen (Gerät ausgesondert, Ziel-Standort gesperrt); nur in der Historie */
    case NotApplied = 'not_applied';
    /** Gerät hat bereits diesen Standort und Status; nichts geschrieben */
    case Unchanged = 'unchanged';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'übernommen',
            self::HistoryOnly => 'nur Historie',
            self::NotApplied => 'nicht übernommen',
            self::Unchanged => 'keine Änderung',
        };
    }
}
