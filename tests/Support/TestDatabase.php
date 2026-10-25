<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseConfig;

/**
 * Zugang zur Testdatenbank. Lädt ausschließlich config/config.test.php.
 */
final class TestDatabase
{
    public const string CONFIG_PATH = __DIR__ . '/../../config/config.test.php';

    public static function configExists(): bool
    {
        return is_file(self::CONFIG_PATH);
    }

    /**
     * Lädt die Testkonfiguration und stellt sicher, dass sie auf eine Testdatenbank zeigt.
     */
    public static function config(): DatabaseConfig
    {
        $config = new ConfigLoader()->loadDatabaseConfig(self::CONFIG_PATH);
        self::assertIsTestDatabaseName($config->name);

        return $config;
    }

    public static function connect(): \PDO
    {
        return new ConnectionFactory()->create(self::config());
    }

    /**
     * Löscht alle Tabellen mit Präfix fe_test_ – ausschließlich in einer Datenbank mit Endung _test.
     * Andere Tabellen (z. B. fe_schema_migrations) werden nie angefasst.
     */
    public static function dropTestTables(\PDO $pdo): void
    {
        self::assertConnectedToTestDatabase($pdo);

        $stmt = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME LIKE 'fe\\_test\\_%'",
        );
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            if (!is_string($table) || preg_match('/^fe_test_[a-z0-9_]+$/D', $table) !== 1) {
                throw new \RuntimeException(sprintf('Unerwarteter Tabellenname beim Aufräumen: %s', (string) $table));
            }
            $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }

    /**
     * Leert alle fachlichen Tabellen (fe_*) – ausschließlich in einer Datenbank mit Endung _test.
     * Nie angefasst werden die Protokolltabelle fe_schema_migrations und Tabellen fe_test_*.
     *
     * DELETE statt TRUNCATE: TRUNCATE ist DDL und legt jede Tabelle neu an (lokal rund 125 ms je
     * Tabelle, vor jedem Test). AUTO_INCREMENT wird daher nicht zurückgesetzt; Tests dürfen sich
     * nicht auf bestimmte IDs verlassen.
     */
    public static function clearDomainTables(\PDO $pdo): void
    {
        self::assertConnectedToTestDatabase($pdo);

        $stmt = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE 'fe\\_%'"
            . " AND TABLE_NAME NOT LIKE 'fe\\_test\\_%' AND TABLE_NAME <> 'fe_schema_migrations'",
        );
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/^fe_[a-z0-9_]+$/D', $table) !== 1) {
                throw new \RuntimeException(sprintf('Unerwarteter Tabellenname beim Leeren: %s', (string) $table));
            }
        }

        // Fremdschlüssel kurz aus, damit die Reihenfolge der Tabellen keine Rolle spielt.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $pdo->exec(sprintf('DELETE FROM `%s`', $table));
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * @throws \RuntimeException wenn die Verbindung nicht auf eine Datenbank mit Endung _test zeigt
     */
    public static function assertConnectedToTestDatabase(\PDO $pdo): void
    {
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        self::assertIsTestDatabaseName(is_string($database) ? $database : '');
    }

    public static function isTestDatabaseName(string $name): bool
    {
        return preg_match('/^.+_test$/D', $name) === 1;
    }

    /**
     * @throws \RuntimeException wenn der Name nicht auf _test endet
     */
    public static function assertIsTestDatabaseName(string $name): void
    {
        if (!self::isTestDatabaseName($name)) {
            throw new \RuntimeException(
                'Der Datenbankname in config/config.test.php endet nicht auf _test. Testlauf abgebrochen.',
            );
        }
    }
}
