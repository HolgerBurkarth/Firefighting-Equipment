<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

use FirefightingEquipment\Device\DeviceException;

/**
 * Die Import-Datei ist als Ganzes unbrauchbar (nicht lesbar, Kopfzeile, Grenzen, abgebrochener
 * Export). $details enthält Angaben für die API (z. B. {"line": 4} oder {"columns": [...]}).
 */
final class ImportFileException extends DeviceException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
