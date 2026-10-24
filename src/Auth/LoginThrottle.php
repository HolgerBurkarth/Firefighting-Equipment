<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Bremse gegen Durchprobieren von Passwörtern (fe_auth_attempts, action = login).
 *
 * Gesperrt wird, wenn im Zeitfenster zu viele Fehlversuche vorliegen:
 * - je E-Mail und Stadt (tenant_id NULL = service): nur Fehlversuche nach der letzten
 *   erfolgreichen Anmeldung zählen;
 * - je IP-Präfix (über alle Städte): großzügiger, da viele Geräte einer Wache hinter einer
 *   Adresse liegen; eine erfolgreiche Anmeldung setzt hier nichts zurück.
 * Abgewiesene Anfragen werden nicht gespeichert, sonst ließe sich die Sperre beliebig verlängern.
 */
final readonly class LoginThrottle
{
    public const int WINDOW_SECONDS = 900;
    public const int MAX_FAILURES_PER_ACCOUNT = 5;
    public const int MAX_FAILURES_PER_IP = 30;
    public const int RETENTION_HOURS = 24;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Schlüssel für eine E-Mail (auch für unbekannte oder ungültige Adressen): SHA-256, binär.
     */
    public static function emailHash(string $email): string
    {
        return hash('sha256', $email, true);
    }

    /**
     * @param int|null $tenantId null = Bereich service
     *
     * @return int|null Sekunden bis zum nächsten erlaubten Versuch; null = nicht gesperrt
     */
    public function retryAfter(?int $tenantId, string $emailHash, string $ipPrefix): ?int
    {
        $account = $this->secondsUntilBelowLimit(
            "SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), attempted_at + INTERVAL ? SECOND) FROM fe_auth_attempts"
            . " WHERE action = 'login' AND tenant_id <=> ? AND email_hash = ? AND succeeded = 0"
            . ' AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? SECOND'
            . ' AND id > (SELECT COALESCE(MAX(id), 0) FROM fe_auth_attempts'
            . "   WHERE action = 'login' AND tenant_id <=> ? AND email_hash = ? AND succeeded = 1)"
            . ' ORDER BY attempted_at DESC, id DESC LIMIT ' . self::MAX_FAILURES_PER_ACCOUNT,
            [self::WINDOW_SECONDS, $tenantId, $emailHash, self::WINDOW_SECONDS, $tenantId, $emailHash],
            self::MAX_FAILURES_PER_ACCOUNT,
        );
        $ip = $this->secondsUntilBelowLimit(
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), attempted_at + INTERVAL ? SECOND) FROM fe_auth_attempts'
            . " WHERE ip_prefix = ? AND action = 'login' AND succeeded = 0"
            . ' AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? SECOND'
            . ' ORDER BY attempted_at DESC, id DESC LIMIT ' . self::MAX_FAILURES_PER_IP,
            [self::WINDOW_SECONDS, $ipPrefix, self::WINDOW_SECONDS],
            self::MAX_FAILURES_PER_IP,
        );

        return $account === null && $ip === null ? null : max($account ?? 0, $ip ?? 0);
    }

    /**
     * @param int|null $tenantId null = Bereich service
     */
    public function record(?int $tenantId, string $emailHash, string $ipPrefix, bool $succeeded): void
    {
        $this->pdo->prepare(
            'INSERT INTO fe_auth_attempts (action, tenant_id, email_hash, ip_prefix, succeeded, attempted_at)'
            . " VALUES ('login', ?, ?, ?, ?, UTC_TIMESTAMP())",
        )->execute([$tenantId, $emailHash, $ipPrefix, $succeeded ? 1 : 0]);
    }

    /**
     * Löscht Versuche (aller Aktionen), die älter als 24 Stunden sind. Ersatz für einen Cronjob.
     *
     * @return int Anzahl gelöschter Einträge
     */
    public function deleteOld(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM fe_auth_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL ? HOUR');
        $stmt->execute([self::RETENTION_HOURS]);

        return $stmt->rowCount();
    }

    /**
     * Die Abfrage liefert je zählendem Fehlversuch (neueste zuerst, höchstens $limit) die Sekunden,
     * bis er aus dem Zeitfenster fällt. Gesperrt ist bei $limit Treffern, bis der älteste davon
     * herausfällt.
     *
     * @param list<int|string|null> $params
     */
    private function secondsUntilBelowLimit(string $sql, array $params, int $limit): ?int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $seconds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (count($seconds) < $limit) {
            return null;
        }

        return max(1, (int) end($seconds));
    }
}
