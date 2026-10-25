<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Gemeinsame Auswertung der Bremsen über fe_auth_attempts (LoginThrottle, PasswordResetThrottle).
 */
final class AttemptWindow
{
    /**
     * Die Abfrage liefert je zählendem Versuch (neueste zuerst, höchstens $limit) die Sekunden, bis
     * er aus dem Zeitfenster fällt. Gesperrt ist bei $limit Treffern, bis der älteste davon
     * herausfällt.
     *
     * @param list<int|string|null> $params
     *
     * @return int|null Sekunden bis zum nächsten erlaubten Versuch; null = nicht gesperrt
     */
    public static function secondsUntilBelowLimit(\PDO $pdo, string $sql, array $params, int $limit): ?int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $seconds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (count($seconds) < $limit) {
            return null;
        }

        return max(1, (int) end($seconds));
    }

    /**
     * Gesperrt, sobald eine der Grenzen erreicht ist; gewartet wird bis zur späteren Freigabe.
     */
    public static function combine(?int ...$retryAfter): ?int
    {
        $values = array_filter($retryAfter, static fn (?int $s): bool => $s !== null);

        return $values === [] ? null : max($values);
    }
}
