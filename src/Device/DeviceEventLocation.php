<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Standort in einem Eintrag der Historie. Die Historie speichert nur die ID; Name und Pfad sind die
 * aktuellen (nach einer Umbenennung erscheint der neue Name).
 */
final readonly class DeviceEventLocation
{
    public function __construct(
        public int $id,
        public string $name,
        /** null = Standort der Ebene 1 */
        public ?string $parentName,
    ) {
    }

    /**
     * Pfad wie „HLF 20 / G1“ (Ebene 1: nur der Name).
     */
    public function path(): string
    {
        return $this->parentName === null ? $this->name : $this->parentName . ' / ' . $this->name;
    }
}
