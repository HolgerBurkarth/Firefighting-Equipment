<?php

declare(strict_types=1);

namespace FirefightingEquipment\Csv;

/**
 * Ein Datensatz einer gelesenen CSV-Datei (CsvReader).
 */
final readonly class CsvRecord
{
    /**
     * @param int          $line   Nummer des Datensatzes wie in Excel (Kopfzeile = 1)
     * @param list<string> $fields Felder in UTF-8, ohne Anführungszeichen der Datei
     */
    public function __construct(
        public int $line,
        public array $fields,
    ) {
    }

    /** Alle Felder leer (z. B. eine Leerzeile oder „;;;“). */
    public function isEmpty(): bool
    {
        return implode('', $this->fields) === '';
    }
}
