<?php

declare(strict_types=1);

namespace FirefightingEquipment\Csv;

/**
 * Ergebnis von CsvReader::read: Kopfzeile und nicht leere Datensätze.
 */
final readonly class CsvTable
{
    /**
     * @param string          $encoding  CsvReader::ENCODING_UTF8 oder ENCODING_WINDOWS_1252
     * @param string          $delimiter erkanntes Trennzeichen (siehe CsvReader::DELIMITERS)
     * @param list<CsvRecord> $records   Datensätze nach der Kopfzeile, ohne leere
     */
    public function __construct(
        public string $encoding,
        public string $delimiter,
        public CsvRecord $header,
        public array $records,
    ) {
    }
}
