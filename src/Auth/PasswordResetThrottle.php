<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Bremse für „Passwort vergessen“ (fe_auth_attempts):
 * - Anforderung (action = password_reset): jede angenommene Anfrage zählt, auch für unbekannte
 *   Adressen (sonst verriete die Bremse, welche Adressen existieren); höchstens 3 je E-Mail und
 *   Stadt (tenant_id NULL = service) und 20 je IP-Präfix (über alle Städte) pro Stunde.
 * - Einlösen (action = reset_confirm, ohne E-Mail): höchstens 10 Fehlversuche (ungültiger Link)
 *   je IP-Präfix in 15 Minuten.
 * Abgewiesene Anfragen werden nicht gespeichert, sonst ließe sich die Sperre beliebig verlängern.
 */
final readonly class PasswordResetThrottle
{
    public const int REQUEST_WINDOW_SECONDS = 3600;
    public const int MAX_REQUESTS_PER_ACCOUNT = 3;
    public const int MAX_REQUESTS_PER_IP = 20;
    public const int CONFIRM_WINDOW_SECONDS = 900;
    public const int MAX_CONFIRM_FAILURES_PER_IP = 10;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param int|null $tenantId null = Bereich service
     *
     * @return int|null Sekunden bis zur nächsten erlaubten Anforderung; null = nicht gesperrt
     */
    public function requestRetryAfter(?int $tenantId, string $emailHash, string $ipPrefix): ?int
    {
        $account = AttemptWindow::secondsUntilBelowLimit(
            $this->pdo,
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), attempted_at + INTERVAL ? SECOND) FROM fe_auth_attempts'
            . " WHERE action = 'password_reset' AND tenant_id <=> ? AND email_hash = ?"
            . ' AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? SECOND'
            . ' ORDER BY attempted_at DESC, id DESC LIMIT ' . self::MAX_REQUESTS_PER_ACCOUNT,
            [self::REQUEST_WINDOW_SECONDS, $tenantId, $emailHash, self::REQUEST_WINDOW_SECONDS],
            self::MAX_REQUESTS_PER_ACCOUNT,
        );
        $ip = AttemptWindow::secondsUntilBelowLimit(
            $this->pdo,
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), attempted_at + INTERVAL ? SECOND) FROM fe_auth_attempts'
            . " WHERE ip_prefix = ? AND action = 'password_reset'"
            . ' AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? SECOND'
            . ' ORDER BY attempted_at DESC, id DESC LIMIT ' . self::MAX_REQUESTS_PER_IP,
            [self::REQUEST_WINDOW_SECONDS, $ipPrefix, self::REQUEST_WINDOW_SECONDS],
            self::MAX_REQUESTS_PER_IP,
        );

        return AttemptWindow::combine($account, $ip);
    }

    /**
     * Speichert eine angenommene Anforderung (succeeded = 1; ob eine Mail ging, steht nirgends).
     *
     * @param int|null $tenantId null = Bereich service
     */
    public function recordRequest(?int $tenantId, string $emailHash, string $ipPrefix): void
    {
        $this->pdo->prepare(
            'INSERT INTO fe_auth_attempts (action, tenant_id, email_hash, ip_prefix, succeeded, attempted_at)'
            . " VALUES ('password_reset', ?, ?, ?, 1, UTC_TIMESTAMP())",
        )->execute([$tenantId, $emailHash, $ipPrefix]);
    }

    /**
     * @return int|null Sekunden bis zum nächsten erlaubten Einlösen; null = nicht gesperrt
     */
    public function confirmRetryAfter(string $ipPrefix): ?int
    {
        return AttemptWindow::secondsUntilBelowLimit(
            $this->pdo,
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), attempted_at + INTERVAL ? SECOND) FROM fe_auth_attempts'
            . " WHERE ip_prefix = ? AND action = 'reset_confirm' AND succeeded = 0"
            . ' AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? SECOND'
            . ' ORDER BY attempted_at DESC, id DESC LIMIT ' . self::MAX_CONFIRM_FAILURES_PER_IP,
            [self::CONFIRM_WINDOW_SECONDS, $ipPrefix, self::CONFIRM_WINDOW_SECONDS],
            self::MAX_CONFIRM_FAILURES_PER_IP,
        );
    }

    /**
     * @param int|null $tenantId null = Bereich service
     */
    public function recordConfirm(?int $tenantId, string $ipPrefix, bool $succeeded): void
    {
        $this->pdo->prepare(
            'INSERT INTO fe_auth_attempts (action, tenant_id, email_hash, ip_prefix, succeeded, attempted_at)'
            . " VALUES ('reset_confirm', ?, NULL, ?, ?, UTC_TIMESTAMP())",
        )->execute([$tenantId, $ipPrefix, $succeeded ? 1 : 0]);
    }
}
