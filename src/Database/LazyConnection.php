<?php

declare(strict_types=1);

namespace FirefightingEquipment\Database;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;

/**
 * Datenbankverbindung, die erst beim ersten Zugriff aufgebaut wird (und erst dann die
 * Konfiguration lädt). Anfragen, die keine Datenbank brauchen, verbinden sich nie.
 */
final class LazyConnection
{
    private ?\PDO $pdo = null;

    /**
     * @param \Closure(): \PDO $factory
     */
    public function __construct(private readonly \Closure $factory)
    {
    }

    public static function fromConfigFile(string $configPath): self
    {
        return new self(static function () use ($configPath): \PDO {
            try {
                $config = new ConfigLoader()->loadDatabaseConfig($configPath);
            } catch (ConfigException $e) {
                throw new ConnectionException('Datenbank-Konfiguration nicht nutzbar.', 0, $e);
            }

            return new ConnectionFactory()->create($config);
        });
    }

    /**
     * @throws ConnectionException
     */
    public function get(): \PDO
    {
        return $this->pdo ??= ($this->factory)();
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }
}
