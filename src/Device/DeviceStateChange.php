<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Gewünschte Umbuchung und/oder Statusänderung (POST devices/{id}/state): mindestens eines von
 * beiden, nie ausgesondert (dafür retire). Ob sich wirklich etwas ändert, entscheidet
 * DeviceRepository; gleiche Werte werden ignoriert.
 *
 * $process: das Ziel gibt ein Ablauf vor ("Benutzt melden" in die Sammelstelle, Phase 2, G4); es darf
 * außerhalb des Datenfilters der Rolle liegen, und das Gerät darf danach daraus fallen.
 */
final readonly class DeviceStateChange
{
    /**
     * @throws InvalidDeviceValueException weder Standort noch Status angegeben
     */
    public function __construct(
        public ?int $locationId,
        public ?DeviceStatus $status,
        public bool $process = false,
    ) {
        if ($locationId === null && $status === null) {
            throw new InvalidDeviceValueException('Bitte einen neuen Standort (location_id) und/oder Status (status) angeben.');
        }
        if ($status === DeviceStatus::Retired) {
            throw new \LogicException('Ausgesondert wird über DeviceRepository::retire().');
        }
    }
}
