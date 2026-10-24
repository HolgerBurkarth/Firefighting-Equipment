<?php

declare(strict_types=1);

namespace FirefightingEquipment\Backup;

use FirefightingEquipment\Database\DatabaseConfig;

/**
 * Kurzlebige Zugangsdatei für mysqldump (--defaults-extra-file), damit Zugangsdaten nie in der
 * Befehlszeile stehen. Wird mit Rechten 600 angelegt; der Aufrufer löscht sie in jedem Fall mit delete().
 */
final class MysqlDefaultsFile
{
    public const string PREFIX = '.ff-db-';

    private function __construct(public readonly string $path)
    {
    }

    /**
     * @throws BackupException
     */
    public static function create(string $directory, DatabaseConfig $config): self
    {
        $content = self::render($config);
        $path = $directory . DIRECTORY_SEPARATOR . self::PREFIX . bin2hex(random_bytes(8)) . '.cnf';

        $file = new self($path);
        $oldUmask = umask(0077);
        try {
            // 'x': nur neu anlegen, nie eine vorhandene Datei überschreiben.
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                throw new BackupException(sprintf('Zugangsdatei konnte nicht angelegt werden in %s.', $directory));
            }
            $written = fwrite($handle, $content);
            fclose($handle);
            if ($written !== strlen($content) || !chmod($path, 0600)) {
                $file->delete();
                throw new BackupException(sprintf('Zugangsdatei konnte nicht geschrieben werden in %s.', $directory));
            }
        } finally {
            umask($oldUmask);
        }

        return $file;
    }

    /**
     * Inhalt im Format der MariaDB-Optionsdateien. Werte stehen in doppelten Anführungszeichen;
     * darin werden nur \ und " maskiert.
     *
     * @throws BackupException bei Zeilenumbrüchen oder Steuerzeichen in den Zugangsdaten
     */
    public static function render(DatabaseConfig $config): string
    {
        $lines = ['[client]'];
        foreach (['user' => $config->user, 'password' => $config->pass, 'host' => $config->host] as $key => $value) {
            if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                // Den Wert selbst nicht nennen: Er kann das Passwort sein.
                throw new BackupException(sprintf("'db.%s' enthält Steuerzeichen (z. B. Zeilenumbruch).", self::configKey($key)));
            }
            $lines[] = sprintf('%s="%s"', $key, str_replace(['\\', '"'], ['\\\\', '\\"'], $value));
        }

        return implode("\n", $lines) . "\n";
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private static function configKey(string $key): string
    {
        return $key === 'password' ? 'pass' : $key;
    }
}
