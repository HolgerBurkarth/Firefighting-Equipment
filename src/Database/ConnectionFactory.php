<?php

declare(strict_types=1);

namespace FirefightingEquipment\Database;

/**
 * Erzeugt die PDO-Verbindung zu MariaDB.
 */
final readonly class ConnectionFactory
{
    public const string CHARSET = 'utf8mb4';
    public const string COLLATION = 'utf8mb4_unicode_ci';

    /**
     * @throws ConnectionException
     */
    public function create(DatabaseConfig $config): \PDO
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config->host, $config->name, self::CHARSET);

        try {
            return \PDO::connect($dsn, $config->user, $config->pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                // Zeitzone als Offset, da benannte Zeitzonen auf dem Server evtl. nicht geladen sind.
                \Pdo\Mysql::ATTR_INIT_COMMAND => sprintf(
                    "SET NAMES %s COLLATE %s, time_zone = '+00:00'",
                    self::CHARSET,
                    self::COLLATION,
                ),
            ]);
        } catch (\PDOException $e) {
            // Die Originalmeldung enthält u. a. den Benutzernamen und wird daher nicht weitergegeben.
            throw new ConnectionException(self::describe($e));
        }
    }

    private static function describe(\PDOException $e): string
    {
        if (preg_match('/^SQLSTATE\[(\w+)\] \[(\d+)\]/', $e->getMessage(), $match) === 1) {
            return sprintf('Verbindung zur Datenbank fehlgeschlagen (SQLSTATE %s, Fehler %s).', $match[1], $match[2]);
        }

        return 'Verbindung zur Datenbank fehlgeschlagen.';
    }
}
