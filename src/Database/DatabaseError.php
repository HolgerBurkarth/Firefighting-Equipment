<?php

declare(strict_types=1);

namespace FirefightingEquipment\Database;

/**
 * Verständliche Beschreibung eines Datenbankfehlers ohne Zugangsdaten.
 */
final class DatabaseError
{
    public static function describe(\PDOException $e): string
    {
        $info = $e->errorInfo;
        if (is_array($info) && isset($info[0], $info[1], $info[2])) {
            return sprintf('SQLSTATE %s, Fehler %s: %s', $info[0], $info[1], self::mask((string) $info[2]));
        }

        return self::mask($e->getMessage());
    }

    /**
     * Ersetzt Benutzer und Host in Meldungen wie "Access denied for user 'u'@'h'".
     */
    public static function mask(string $message): string
    {
        return preg_replace("/'[^']*'@'[^']*'/", "'***'@'***'", $message) ?? 'Datenbankfehler.';
    }
}
