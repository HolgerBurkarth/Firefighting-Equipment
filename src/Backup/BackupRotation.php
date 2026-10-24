<?php

declare(strict_types=1);

namespace FirefightingEquipment\Backup;

/**
 * Löscht alte Datenbanksicherungen. Betrachtet werden nur reguläre Dateien, deren Name
 * genau dem Muster von BackupFileName entspricht; alle anderen Dateien bleiben unberührt.
 */
final readonly class BackupRotation
{
    public const int KEEP = 10;

    /**
     * @return list<string> Pfade der gelöschten Dateien
     *
     * @throws BackupException
     */
    public static function prune(string $directory, int $keep = self::KEEP): array
    {
        if ($keep < 1) {
            throw new \InvalidArgumentException('Es muss mindestens eine Sicherung behalten werden.');
        }

        $entries = scandir($directory);
        if ($entries === false) {
            throw new BackupException(sprintf('Sicherungsordner nicht lesbar: %s', $directory));
        }

        $backups = [];
        foreach ($entries as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (BackupFileName::matches($entry) && is_file($path) && !is_link($path)) {
                $backups[] = $entry;
            }
        }
        sort($backups, SORT_STRING);

        $deleted = [];
        foreach (array_slice($backups, 0, max(0, count($backups) - $keep)) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!unlink($path)) {
                throw new BackupException(sprintf('Alte Sicherung konnte nicht gelöscht werden: %s', $path));
            }
            $deleted[] = $path;
        }

        return $deleted;
    }
}
