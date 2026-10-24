<?php

declare(strict_types=1);

namespace FirefightingEquipment\Backup;

/**
 * Löscht alte Datenbanksicherungen. Betrachtet werden nur reguläre Dateien, deren Name
 * genau dem Muster von BackupFileName entspricht; alle anderen Dateien bleiben unberührt.
 *
 * Sortiert wird nach dem Änderungsdatum der Datei (bei Gleichstand nach Namen), nicht nach dem
 * Zeitstempel im Namen. Die gerade erzeugte Sicherung ($keepFile) wird nie gelöscht und zählt
 * zu den behaltenen.
 */
final readonly class BackupRotation
{
    public const int KEEP = 10;

    /**
     * @param string|null $keepFile Dateiname (ohne Ordner) der Sicherung, die nie gelöscht wird
     *
     * @return list<string> Pfade der gelöschten Dateien, älteste zuerst
     *
     * @throws BackupException
     */
    public static function prune(string $directory, int $keep = self::KEEP, ?string $keepFile = null): array
    {
        if ($keep < 1) {
            throw new \InvalidArgumentException('Es muss mindestens eine Sicherung behalten werden.');
        }

        $entries = scandir($directory);
        if ($entries === false) {
            throw new BackupException(sprintf('Sicherungsordner nicht lesbar: %s', $directory));
        }

        clearstatcache();
        $protected = false;
        /** @var array<string, int> $backups Name => Änderungsdatum */
        $backups = [];
        foreach ($entries as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!BackupFileName::matches($entry) || !is_file($path) || is_link($path)) {
                continue;
            }
            if ($entry === $keepFile) {
                $protected = true;
                continue;
            }
            $mtime = filemtime($path);
            if ($mtime === false) {
                throw new BackupException(sprintf('Änderungsdatum nicht lesbar: %s', $path));
            }
            $backups[$entry] = $mtime;
        }

        // Neueste zuerst; bei gleichem Änderungsdatum entscheidet der Name.
        uksort($backups, static fn (string $a, string $b): int => [$backups[$b], $b] <=> [$backups[$a], $a]);
        $obsolete = array_reverse(array_slice(array_keys($backups), $protected ? $keep - 1 : $keep));

        $deleted = [];
        foreach ($obsolete as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!unlink($path)) {
                throw new BackupException(sprintf('Alte Sicherung konnte nicht gelöscht werden: %s', $path));
            }
            $deleted[] = $path;
        }

        return $deleted;
    }
}
