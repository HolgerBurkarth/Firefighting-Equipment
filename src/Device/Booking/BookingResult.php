<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Booking;

use FirefightingEquipment\Device\Device;

/**
 * Ergebnis einer Buchung mit dem aktuellen Stand des Geräts. $replayed: Dieselbe Buchung wurde
 * bereits verarbeitet, das gespeicherte Ergebnis wird wiederholt (nichts geschrieben).
 */
final readonly class BookingResult
{
    public function __construct(
        public Device $device,
        public BookingOutcome $outcome,
        public ?BookingReason $reason = null,
        public bool $staleConfirmed = false,
        public ?ClientRef $clientRef = null,
        public bool $replayed = false,
        /** Eintrag der Historie (bei unchanged keiner) */
        public ?int $eventId = null,
        /** Hinweis an die Admins, falls einer angelegt wurde */
        public ?int $noticeId = null,
    ) {
    }
}
