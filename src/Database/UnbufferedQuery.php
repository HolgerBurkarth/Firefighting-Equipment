<?php

declare(strict_types=1);

namespace FirefightingEquipment\Database;

/**
 * Liest eine Abfrage Zeile für Zeile ungepuffert (für Exporte): Weder PHP noch der Treiber halten
 * das ganze Ergebnis im Speicher.
 *
 * Solange der Generator läuft, darf über dieselbe Verbindung keine andere Abfrage laufen (MariaDB
 * lehnt das ab); alles Nötige gehört daher per JOIN in die eine Abfrage. Zum Schluss (auch bei
 * Abbruch oder wenn der Generator vorzeitig verworfen wird) wird der Rest verworfen und die
 * vorherige Einstellung der Verbindung wiederhergestellt.
 */
final class UnbufferedQuery
{
    /**
     * @template T
     *
     * @param list<int|string>                   $params
     * @param \Closure(array<string, mixed>): T $map
     *
     * @return \Generator<int, T>
     */
    public static function rows(\PDO $pdo, string $sql, array $params, \Closure $map): \Generator
    {
        $buffered = (bool) $pdo->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
        $stmt = null;
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            while (is_array($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
                yield $map($row);
            }
        } finally {
            $stmt?->closeCursor();
            $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }
}
