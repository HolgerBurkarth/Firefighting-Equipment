<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

/**
 * Unbekannter Gerätetyp bzw. Standort in der Datei, mit ähnlichen gültigen Werten
 * (ImportSuggestions; leer, wenn keiner ähnlich genug ist).
 */
final readonly class UnknownImportValue
{
    /**
     * @param list<string> $suggestions
     */
    public function __construct(
        /** wie in der Datei */
        public string $value,
        public array $suggestions,
    ) {
    }
}
