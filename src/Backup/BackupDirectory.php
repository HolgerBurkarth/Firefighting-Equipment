<?php

declare(strict_types=1);

namespace FirefightingEquipment\Backup;

/**
 * Prüft den Zielordner der Sicherungen. Er muss bereits existieren und per .htaccess gesperrt sein.
 */
final readonly class BackupDirectory
{
    /**
     * @return string absoluter Pfad des Ordners
     *
     * @throws BackupException
     */
    public static function assertUsable(string $directory): string
    {
        if (!is_dir($directory)) {
            throw new BackupException(sprintf('Sicherungsordner existiert nicht: %s', $directory));
        }
        if (!is_file($directory . DIRECTORY_SEPARATOR . '.htaccess')) {
            throw new BackupException(sprintf(
                'Im Sicherungsordner %s fehlt die .htaccess – Sicherungen wären ungeschützt. Abbruch.',
                $directory,
            ));
        }
        if (!is_writable($directory)) {
            throw new BackupException(sprintf('Sicherungsordner ist nicht beschreibbar: %s', $directory));
        }

        return realpath($directory) ?: $directory;
    }
}
