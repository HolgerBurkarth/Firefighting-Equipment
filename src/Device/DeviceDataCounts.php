<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Anzahl Geräte, Ereignisse, Standorte, Buchungs-Quittungen und Hinweise einer Stadt (vorhanden
 * bzw. gelöscht; der Verlauf der Hinweise zählt nicht eigens).
 */
final readonly class DeviceDataCounts
{
    public function __construct(
        public int $devices,
        public int $events,
        public int $locations,
        public int $bookings = 0,
        public int $notices = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->devices === 0 && $this->events === 0 && $this->locations === 0 && $this->bookings === 0 && $this->notices === 0;
    }
}
