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
