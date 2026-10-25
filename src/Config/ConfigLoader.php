<?php

declare(strict_types=1);

namespace FirefightingEquipment\Config;

use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Mail\MailConfig;
use FirefightingEquipment\Mail\MailTransport;

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
        $http = $this->optionalGroup($this->loadFile($path), 'http', ['allowed_origins'], $path);

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
     * Optionale Gruppe 'app' (Schlüssel 'base_url', Format wie ein Origin). Fehlt sie oder der
     * Schlüssel: Standardwerte (Produktion).
     *
     * @throws ConfigException
     */
    public function loadAppConfig(string $path): AppConfig
    {
        $app = $this->optionalGroup($this->loadFile($path), 'app', ['base_url'], $path);
        if (!array_key_exists('base_url', $app)) {
            return new AppConfig();
        }

        $baseUrl = $app['base_url'];
        if (!is_string($baseUrl) || preg_match(self::ORIGIN_PATTERN, $baseUrl) !== 1) {
            throw new ConfigException(sprintf(
                "'app.base_url' in %s ist ungültig (erwartet z. B. http://localhost:8000, ohne Pfad und ohne / am Ende).",
                $path,
            ));
        }

        return new AppConfig($baseUrl);
    }

    /**
     * Optionale Gruppe 'mail' (Schlüssel 'transport' = sendmail oder file, 'file_dir'). Fehlt sie
     * oder ein Schlüssel: Standardwerte (Produktion: sendmail).
     *
     * @throws ConfigException
     */
    public function loadMailConfig(string $path): MailConfig
    {
        $mail = $this->optionalGroup($this->loadFile($path), 'mail', ['transport', 'file_dir'], $path);

        $transport = MailTransport::Sendmail;
        if (array_key_exists('transport', $mail)) {
            $transport = (is_string($mail['transport']) ? MailTransport::tryFrom($mail['transport']) : null)
                ?? throw new ConfigException(sprintf("'mail.transport' muss in %s 'sendmail' oder 'file' sein.", $path));
        }

        $fileDir = $mail['file_dir'] ?? MailConfig::DEFAULT_FILE_DIR;
        if (!is_string($fileDir) || trim($fileDir) === '' || str_contains($fileDir, "\0")) {
            throw new ConfigException(sprintf("'mail.file_dir' muss in %s ein nicht leerer Pfad sein.", $path));
        }

        return new MailConfig($transport, $fileDir);
    }

    /**
     * Inhalt einer optionalen Gruppe; fehlt sie, ein leeres Array. Unbekannte Schlüssel sind ein Fehler.
     *
     * @param array<array-key, mixed> $config
     * @param list<string>            $keys erlaubte Schlüssel
     *
     * @return array<array-key, mixed>
     *
     * @throws ConfigException
     */
    private function optionalGroup(array $config, string $group, array $keys, string $path): array
    {
        if (!array_key_exists($group, $config)) {
            return [];
        }

        $values = $config[$group];
        if (!is_array($values)) {
            throw new ConfigException(sprintf("'%s' muss in %s ein Array sein.", $group, $path));
        }
        foreach (array_keys($values) as $key) {
            if (!in_array($key, $keys, true)) {
                throw new ConfigException(sprintf("Unbekannter Schlüssel '%s.%s' in %s.", $group, $key, $path));
            }
        }

        return $values;
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
