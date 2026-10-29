<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Seed;

/**
 * Angelegte Testdaten: Standorte (mit Fächern), Geräte, Schritte im Verlauf (einschließlich
 * Aussondern), davon ausgesondert.
 */
final readonly class TestCitySeedResult
{
    public function __construct(
        public int $locations,
        public int $devices,
        public int $steps,
        public int $retired,
    ) {
    }
}
