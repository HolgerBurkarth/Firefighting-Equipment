<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Permission\DeviceVisibility;

/**
 * Standort in einem Eintrag der Historie oder einem Hinweis. Die Historie speichert nur die ID; Name
 * und Pfad sind die aktuellen (nach einer Umbenennung erscheint der neue Name). Ein Standort außerhalb
 * des Datenfilters erscheint als „anderer Standort“ ohne ID (masked(), Plan Phase 1, Abschnitt 4).
 */
final readonly class DeviceEventLocation
{
    public function __construct(
        /** null = außerhalb des Datenfilters (isMasked) */
        public ?int $id,
        public string $name,
        /** null = Standort der Ebene 1 */
        public ?string $parentName,
    ) {
    }

    /**
     * Derselbe Standort, oder „anderer Standort“, wenn das Konto ihn nicht sieht.
     */
    public function maskedFor(DeviceVisibility $visibility): self
    {
        return $this->id === null || $visibility->allowsLocation($this->id)
            ? $this
            : new self(null, DeviceVisibility::OTHER_LOCATION, null);
    }

    public function isMasked(): bool
    {
        return $this->id === null;
    }

    /**
     * Pfad wie „HLF 20 / G1“ (Ebene 1: nur der Name; maskiert „anderer Standort“).
     */
    public function path(): string
    {
        return $this->parentName === null ? $this->name : $this->parentName . ' / ' . $this->name;
    }
}
