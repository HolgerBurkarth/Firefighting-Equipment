<?php

declare(strict_types=1);

namespace FirefightingEquipment\Backup;

/**
 * Ergebnis einer Sicherung: Pfad der neuen Datei und die beim Aufräumen gelöschten alten.
 */
final readonly class BackupResult
{
    /**
     * @param list<string> $deleted Pfade der gelöschten Sicherungen, älteste zuerst
     */
    public function __construct(
        public string $path,
        public array $deleted,
    ) {
    }
}
