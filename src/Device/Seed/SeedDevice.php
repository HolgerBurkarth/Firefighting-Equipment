<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Seed;

use FirefightingEquipment\Device\DeviceStatus;

/**
 * Ein Gerät der Testdaten (TestCityData), Standort als Pfad wie in der App („HLF 20 / G1“ oder nur
 * „HLF 20“ = ganzes Fahrzeug), Gerätetyp über den Namen eines Start-Gerätetyps.
 */
final readonly class SeedDevice
{
    public function __construct(
        public string $identifier,
        public string $deviceType,
        public string $location,
        public DeviceStatus $status,
        public ?string $scanCode,
        public ?string $serialNumber,
        public ?string $manufacturer,
        public ?string $model,
        /** JJJJ-MM-TT */
        public ?string $procuredOn,
        /** JJJJ-MM-TT */
        public ?string $nextInspectionDue,
        public ?string $note = null,
    ) {
    }
}
