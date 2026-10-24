<?php

declare(strict_types=1);

namespace FirefightingEquipment\Backup;

use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Database\DatabaseError;

/**
 * Erstellt mit mysqldump eine gzip-komprimierte Sicherung ausgewählter Tabellen.
 *
 * Zugangsdaten stehen nur in einer kurzlebigen Zugangsdatei (Rechte 600), nie in der Befehlszeile.
 * Geschrieben wird zuerst in eine Datei .part; erst eine vollständige Sicherung (Exit-Code 0 und
 * Abschlusszeile "-- Dump completed") erhält den endgültigen Namen. Sonst wird die Datei gelöscht.
 */
final readonly class DatabaseDumper
{
    public const string COMPLETION_MARKER = '-- Dump completed';

    /** Optionen für Shared Hosting: keine globalen Sperren, keine Rechte für Tablespaces nötig. */
    public const array OPTIONS = [
        '--single-transaction',
        '--skip-lock-tables',
        '--no-tablespaces',
        '--default-character-set=utf8mb4',
        '--quick',
        '--hex-blob',
    ];

    private const int CHUNK_SIZE = 65536;
    private const int TAIL_SIZE = 1024;
    private const int MAX_ERROR_LENGTH = 2000;

    /**
     * @param list<string> $program Programmaufruf ohne Optionen, z. B. ['mysqldump']
     */
    public function __construct(private array $program = ['mysqldump'])
    {
        if ($program === []) {
            throw new \InvalidArgumentException('Programm für mysqldump fehlt.');
        }
    }

    /**
     * @param list<string> $tables nur Namen fe_[a-z0-9_]+; darf nicht leer sein, sonst würde
     *                             mysqldump die ganze Datenbank sichern
     *
     * @return string Pfad der fertigen Sicherung (Rechte 600)
     *
     * @throws BackupException
     */
    public function dump(DatabaseConfig $config, array $tables, string $directory, string $filename): string
    {
        if ($tables === []) {
            throw new BackupException('Keine Tabellen fe_* vorhanden – nichts zu sichern.');
        }
        foreach ($tables as $table) {
            if (preg_match('/^fe_[a-z0-9_]+$/D', $table) !== 1) {
                throw new BackupException(sprintf('Ungültiger Tabellenname: %s', $table));
            }
        }
        if (!BackupFileName::matches($filename)) {
            throw new BackupException(sprintf('Ungültiger Dateiname der Sicherung: %s', $filename));
        }

        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        $part = $target . '.part';
        if (file_exists($target) || file_exists($part)) {
            throw new BackupException(sprintf('Sicherung existiert bereits: %s', $target));
        }

        $errorFile = $directory . DIRECTORY_SEPARATOR . MysqlDefaultsFile::PREFIX . bin2hex(random_bytes(8)) . '.err';
        $defaults = MysqlDefaultsFile::create($directory, $config);
        $oldUmask = umask(0077);
        try {
            $command = [
                ...$this->program,
                // Muss die erste Option sein.
                '--defaults-extra-file=' . $defaults->path,
                ...self::OPTIONS,
                '--',
                $config->name,
                ...$tables,
            ];
            [$exitCode, $tail] = $this->run($command, $part, $errorFile);

            if ($exitCode !== 0) {
                throw new BackupException(sprintf(
                    'mysqldump ist fehlgeschlagen (Exit-Code %d)%s',
                    $exitCode,
                    self::describeError($errorFile, $config),
                ));
            }
            if (!self::isComplete($tail)) {
                throw new BackupException(sprintf(
                    'Sicherung ist unvollständig (Abschlusszeile "%s" fehlt)%s',
                    self::COMPLETION_MARKER,
                    self::describeError($errorFile, $config),
                ));
            }
            if (!rename($part, $target) || !chmod($target, 0600)) {
                throw new BackupException(sprintf('Sicherung konnte nicht abgeschlossen werden: %s', $target));
            }

            return $target;
        } finally {
            umask($oldUmask);
            $defaults->delete();
            foreach ([$errorFile, $part] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Vollständig, wenn die letzte nicht leere Zeile die Abschlusszeile von mysqldump ist.
     */
    public static function isComplete(string $tail): bool
    {
        $lines = preg_split('/\R/', rtrim($tail)) ?: [];

        return str_starts_with((string) end($lines), self::COMPLETION_MARKER);
    }

    /**
     * Entfernt Zugangsdaten aus einer Meldung von mysqldump.
     */
    public static function mask(string $message, DatabaseConfig $config): string
    {
        // Passwort zuerst, falls Benutzer oder Host darin vorkommen.
        foreach ([$config->pass, $config->user, $config->host] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return DatabaseError::mask($message);
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string} Exit-Code und Ende der unkomprimierten Ausgabe
     *
     * @throws BackupException
     */
    private function run(array $command, string $part, string $errorFile): array
    {
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new BackupException(sprintf('mysqldump konnte nicht gestartet werden: %s', $this->program[0]));
        }
        fclose($pipes[0]);

        $gz = gzopen($part, 'wb6');
        if ($gz === false) {
            fclose($pipes[1]);
            proc_close($process);
            throw new BackupException(sprintf('Sicherungsdatei konnte nicht angelegt werden: %s', $part));
        }

        $tail = '';
        $writeFailed = false;
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], self::CHUNK_SIZE);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                continue;
            }
            if (!$writeFailed && gzwrite($gz, $chunk) !== strlen($chunk)) {
                // Weiterlesen, damit mysqldump nicht an einer vollen Pipe hängen bleibt.
                $writeFailed = true;
            }
            $tail = substr($tail . $chunk, -self::TAIL_SIZE);
        }
        fclose($pipes[1]);
        $closed = gzclose($gz);
        $exitCode = proc_close($process);

        if ($writeFailed || !$closed) {
            throw new BackupException(sprintf('Schreiben der Sicherung fehlgeschlagen (Speicherplatz?): %s', $part));
        }

        return [$exitCode, $tail];
    }

    private static function describeError(string $errorFile, DatabaseConfig $config): string
    {
        // Erst maskieren, dann kürzen: Sonst könnte ein abgeschnittenes Passwort stehen bleiben.
        $message = trim(self::mask((string) @file_get_contents($errorFile), $config));
        if ($message === '') {
            return '.';
        }
        if (strlen($message) > self::MAX_ERROR_LENGTH) {
            $message = mb_strcut($message, 0, self::MAX_ERROR_LENGTH) . ' ...';
        }

        return ': ' . $message;
    }
}
