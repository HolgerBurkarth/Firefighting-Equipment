<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Seed;

use FirefightingEquipment\Device\DeviceStatus;

/**
 * Ein Schritt im Verlauf eines Testgeräts: Umbuchung und/oder Statuswechsel (DeviceRepository::
 * changeState) oder, mit $retire, Aussondern (DeviceRepository::retire).
 */
final readonly class SeedStep
{
    public function __construct(
        public string $identifier,
        /** Pfad wie SeedDevice::$location; null = Standort bleibt */
        public ?string $location,
        public ?DeviceStatus $status,
        public string $comment,
        public bool $retire = false,
    ) {
    }

    public static function retire(string $identifier, string $comment): self
    {
        return new self($identifier, null, null, $comment, true);
    }
}
