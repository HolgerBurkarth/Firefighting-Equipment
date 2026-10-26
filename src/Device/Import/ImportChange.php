<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

/**
 * Eine Änderung in der Vorschau: Werte in der Schreibweise der Datei (Namen, Pfade, deutsche
 * Statusbezeichnungen, Datum TT.MM.JJJJ); null = leer. Bei neuen Geräten ist $old immer null.
 */
final readonly class ImportChange
{
    public function __construct(
        public ImportColumn $column,
        public ?string $old,
        public ?string $new,
    ) {
    }
}
