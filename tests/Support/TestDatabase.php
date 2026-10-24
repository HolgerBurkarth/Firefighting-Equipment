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
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        self::assertIsTestDatabaseName(is_string($database) ? $database : '');

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
