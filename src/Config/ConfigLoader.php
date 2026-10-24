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

    /** Origin wie ihn Browser senden: Schema, Host (klein), optional Port, ohne Pfad. */
    private const string ORIGIN_PATTERN = '/^https?:\/\/[a-z0-9.-]+(:[0-9]{1,5})?$/D';

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
     * Zusätzlich erlaubte Origins aus der optionalen Gruppe 'http' (Schlüssel 'allowed_origins'),
     * z. B. ['http://localhost:8000'] für die lokale Entwicklung. Fehlt die Gruppe: keine.
     *
     * @return list<string>
     *
     * @throws ConfigException
     */
    public function loadAllowedOrigins(string $path): array
    {
        $config = $this->loadFile($path);
        if (!array_key_exists('http', $config)) {
            return [];
        }

        $http = $config['http'];
        if (!is_array($http)) {
            throw new ConfigException(sprintf("'http' muss in %s ein Array sein.", $path));
        }
        foreach (array_keys($http) as $key) {
            if ($key !== 'allowed_origins') {
                throw new ConfigException(sprintf("Unbekannter Schlüssel 'http.%s' in %s.", $key, $path));
            }
        }

        $origins = $http['allowed_origins'] ?? [];
        if (!is_array($origins) || !array_is_list($origins)) {
            throw new ConfigException(sprintf("'http.allowed_origins' muss in %s eine Liste sein.", $path));
        }
        foreach ($origins as $origin) {
            if (!is_string($origin) || preg_match(self::ORIGIN_PATTERN, $origin) !== 1) {
                throw new ConfigException(sprintf(
                    "'http.allowed_origins' in %s enthält einen ungültigen Eintrag (erwartet z. B. http://localhost:8000).",
                    $path,
                ));
            }
        }

        return $origins;
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
