<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Anzahl Geräte, Ereignisse und Standorte einer Stadt (vorhanden bzw. gelöscht).
 */
final readonly class DeviceDataCounts
{
    public function __construct(
        public int $devices,
        public int $events,
        public int $locations,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->devices === 0 && $this->events === 0 && $this->locations === 0;
    }
}
