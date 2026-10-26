<?php

declare(strict_types=1);

namespace FirefightingEquipment\Backup;

use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseConfig;

/**
 * Ablauf einer Datenbanksicherung (bin/db-dump.php und tenant.php purge-device-data): Tabellen fe_*
 * ermitteln, mit mysqldump sichern, alte Sicherungen aufräumen.
 *
 * open() prüft Zielordner und zlib, bevor die Konfiguration geladen wird.
 */
final readonly class DatabaseBackup
{
    /**
     * @param list<string> $program Programmaufruf von mysqldump
     * @param (\Closure(DatabaseConfig): list<string>)|null $listTables ermittelt die Tabellen fe_*;
     *        Standard: BackupTables::list über eine neue Verbindung (für Tests austauschbar)
     */
    private function __construct(
        private string $directory,
        private array $program,
        private ?\Closure $listTables,
    ) {
    }

    /**
     * @param list<string> $program
     * @param (\Closure(DatabaseConfig): list<string>)|null $listTables
     *
     * @throws BackupException Zielordner nicht nutzbar oder zlib fehlt
     */
    public static function open(string $directory, array $program = ['mysqldump'], ?\Closure $listTables = null): self
    {
        $directory = BackupDirectory::assertUsable($directory);
        if (!extension_loaded('zlib')) {
            throw new BackupException('PHP-Erweiterung zlib fehlt.');
        }

        return new self($directory, $program, $listTables);
    }

    /**
     * @return BackupResult|null null, wenn es keine Tabelle fe_* gibt (keine Datei, kein mysqldump)
     *
     * @throws BackupException
     * @throws \PDOException
     */
    public function create(DatabaseConfig $config, ?string $suffix): ?BackupResult
    {
        $tables = ($this->listTables ?? self::listTables(...))($config);
        if ($tables === []) {
            return null;
        }

        $filename = BackupFileName::create(new \DateTimeImmutable('now'), $suffix);
        $path = new DatabaseDumper($this->program)->dump($config, $tables, $this->directory, $filename);
        $deleted = BackupRotation::prune($this->directory, BackupRotation::KEEP, basename($path));

        return new BackupResult($path, $deleted);
    }

    /**
     * @return list<string>
     *
     * @throws BackupException
     */
    private static function listTables(DatabaseConfig $config): array
    {
        return BackupTables::list(new ConnectionFactory()->create($config));
    }
}
