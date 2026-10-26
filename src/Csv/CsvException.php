<?php

declare(strict_types=1);

namespace FirefightingEquipment\Csv;

/**
 * Die CSV-Datei ist nicht lesbar (Kodierung, Anführungszeichen, leer). Die Meldung ist für Benutzer.
 */
final class CsvException extends \RuntimeException
{
    /**
     * @param int|null $lineNumber betroffene Zeile (wie in Excel), falls bekannt
     */
    public function __construct(string $message, public readonly ?int $lineNumber = null)
    {
        parent::__construct($message);
    }
}
