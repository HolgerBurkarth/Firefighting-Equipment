<?php

declare(strict_types=1);

namespace FirefightingEquipment\Config;

use FirefightingEquipment\Database\DatabaseConfig;

/**
 * Lädt eine Konfigurationsdatei im Format von config/config.example.php und prüft sie.
 */
final readonly class ConfigLoader
{
    private const array DB_KEYS = ['host', 'name', 'user', 'pass'];

    /** Schlüssel, die nicht leer sein dürfen; ein leeres Passwort ist erlaubt. */
    private const array NON_EMPTY_DB_KEYS = ['host', 'name', 'user'];

    /**
     * @throws ConfigException
     */
    public function loadDatabaseConfig(string $path): DatabaseConfig
    {
        $config = $this->loadFile($path);

        if (!array_key_exists('db', $config)) {
            throw new ConfigException(sprintf("Gruppe 'db' fehlt in %s.", $path));
        }

        $db = $config['db'];
        if (!is_array($db)) {
            throw new ConfigException(sprintf("'db' muss in %s ein Array sein.", $path));
        }

        foreach (self::DB_KEYS as $key) {
            if (!array_key_exists($key, $db)) {
                throw new ConfigException(sprintf("Schlüssel 'db.%s' fehlt in %s.", $key, $path));
            }
            if (!is_string($db[$key])) {
                throw new ConfigException(sprintf("'db.%s' muss in %s ein String sein.", $key, $path));
            }
            if (in_array($key, self::NON_EMPTY_DB_KEYS, true) && trim($db[$key]) === '') {
                throw new ConfigException(sprintf("'db.%s' darf in %s nicht leer sein.", $key, $path));
            }
        }

        foreach (array_keys($db) as $key) {
            if (!in_array($key, self::DB_KEYS, true)) {
                throw new ConfigException(sprintf("Unbekannter Schlüssel 'db.%s' in %s.", $key, $path));
            }
        }

        return new DatabaseConfig($db['host'], $db['name'], $db['user'], $db['pass']);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConfigException
     */
    private function loadFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException(sprintf('Konfigurationsdatei nicht gefunden oder nicht lesbar: %s', $path));
        }

        // Ausgaben der Datei verwerfen; den Text eines PHP-Fehlers nicht weitergeben,
        // da er Ausschnitte aus der Datei enthalten kann.
        ob_start();
        try {
            $config = (static fn (string $file): mixed => require $file)($path);
        } catch (\Throwable) {
            throw new ConfigException(sprintf('Konfigurationsdatei %s enthält einen PHP-Fehler.', $path));
        } finally {
            ob_end_clean();
        }

        if (!is_array($config)) {
            throw new ConfigException(sprintf('Konfigurationsdatei %s muss ein Array zurückgeben.', $path));
        }

        return $config;
    }
}
