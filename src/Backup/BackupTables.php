<?php

declare(strict_types=1);

namespace FirefightingEquipment\Backup;

/**
 * Ermittelt die zu sichernden Tabellen: nur Tabellen mit Präfix fe_ der aktuellen Datenbank.
 */
final readonly class BackupTables
{
    /**
     * @return list<string> nach Namen sortiert
     *
     * @throws BackupException bei unerwarteten Tabellennamen
     */
    public static function list(\PDO $pdo): array
    {
        $tables = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME LIKE 'fe\\_%' ORDER BY TABLE_NAME",
        )->fetchAll(\PDO::FETCH_COLUMN);

        $result = [];
        foreach ($tables as $table) {
            // Die Namen werden als Argumente an mysqldump übergeben und sind daher streng beschränkt.
            if (!is_string($table) || preg_match('/^fe_[a-z0-9_]+$/D', $table) !== 1) {
                throw new BackupException(sprintf('Unerwarteter Tabellenname: %s', (string) $table));
            }
            $result[] = $table;
        }

        return $result;
    }
}
