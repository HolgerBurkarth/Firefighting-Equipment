<?php

declare(strict_types=1);

namespace FirefightingEquipment\Migration;

/**
 * Zerlegt den Inhalt einer Migrationsdatei an ";" in einzelne Anweisungen.
 *
 * Beachtet Zeichenketten ('…', "…", `…`) sowie Kommentare (--, #, /* … *\/).
 * DELIMITER wird nicht unterstützt, Trigger und Prozeduren daher nicht.
 */
final class SqlSplitter
{
    private const string WHITESPACE = " \t\n\r\f\v";

    /**
     * @return list<string> Anweisungen ohne abschließendes ";"; reine Kommentare entfallen
     *
     * @throws MigrationException
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $hasCode = false;
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = self::findQuoteEnd($sql, $i);
                $current .= substr($sql, $i, $end - $i + 1);
                $hasCode = true;
                $i = $end + 1;
                continue;
            }

            // MariaDB erkennt "--" nur mit folgendem Leerraum als Kommentar.
            if ($char === '#' || ($char === '-' && $next === '-' && self::isWhitespaceOrEnd($sql, $i + 2))) {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $current .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new MigrationException('Nicht abgeschlossener Kommentar /* … */.');
                }
                // Ausführbare Kommentare (/*! … */, /*M! … */) zählen als Code.
                $body = substr($sql, $i + 2, 2);
                if (str_starts_with($body, '!') || $body === 'M!') {
                    $hasCode = true;
                }
                $current .= substr($sql, $i, $end + 2 - $i);
                $i = $end + 2;
                continue;
            }

            if ($char === ';') {
                self::finish($statements, $current, $hasCode);
                $current = '';
                $hasCode = false;
                $i++;
                continue;
            }

            if (!str_contains(self::WHITESPACE, $char)) {
                $hasCode = true;
            }
            $current .= $char;
            $i++;
        }

        self::finish($statements, $current, $hasCode);

        return $statements;
    }

    /**
     * @return int Position des schließenden Anführungszeichens
     *
     * @throws MigrationException
     */
    private static function findQuoteEnd(string $sql, int $start): int
    {
        $quote = $sql[$start];
        $length = strlen($sql);
        $i = $start + 1;

        while ($i < $length) {
            $char = $sql[$i];
            if ($char === '\\' && $quote !== '`') {
                $i += 2;
                continue;
            }
            if ($char === $quote) {
                // Verdoppeltes Anführungszeichen steht für das Zeichen selbst.
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;
                    continue;
                }

                return $i;
            }
            $i++;
        }

        throw new MigrationException(sprintf('Nicht abgeschlossene Zeichenkette (beginnt mit %s).', $quote));
    }

    private static function isWhitespaceOrEnd(string $sql, int $position): bool
    {
        return $position >= strlen($sql) || str_contains(self::WHITESPACE, $sql[$position]);
    }

    /**
     * @param list<string> $statements
     *
     * @throws MigrationException
     */
    private static function finish(array &$statements, string $current, bool $hasCode): void
    {
        if (!$hasCode) {
            return;
        }

        $statement = trim($current);
        if (preg_match('/^\s*DELIMITER\s/im', $statement) === 1) {
            throw new MigrationException('DELIMITER wird nicht unterstützt (keine Trigger oder Prozeduren).');
        }

        $statements[] = $statement;
    }
}
