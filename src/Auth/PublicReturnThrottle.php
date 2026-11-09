<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Bremse für die Rückgabe ohne Anmeldung (action = public_return, ohne E-Mail, nur unter Städten):
 * höchstens 20 Fehlversuche (ungültiger Schlüssel oder Code) je IP-Präfix in 15 Minuten. Abgewiesene
 * Anfragen werden nicht gespeichert, sonst ließe sich die Sperre beliebig verlängern.
 */
final readonly class PublicReturnThrottle
{
    public const int WINDOW_SECONDS = 900;
    public const int MAX_FAILURES_PER_IP = 20;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return int|null Sekunden bis zum nächsten erlaubten Versuch; null = nicht gesperrt
     */
    public function retryAfter(string $ipPrefix): ?int
    {
        return AttemptWindow::secondsUntilBelowLimit(
            $this->pdo,
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), attempted_at + INTERVAL ? SECOND) FROM fe_auth_attempts'
            . " WHERE ip_prefix = ? AND action = 'public_return' AND succeeded = 0"
            . ' AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? SECOND'
            . ' ORDER BY attempted_at DESC, id DESC LIMIT ' . self::MAX_FAILURES_PER_IP,
            [self::WINDOW_SECONDS, $ipPrefix, self::WINDOW_SECONDS],
            self::MAX_FAILURES_PER_IP,
        );
    }

    public function record(int $tenantId, string $ipPrefix, bool $succeeded): void
    {
        $this->pdo->prepare(
            'INSERT INTO fe_auth_attempts (action, tenant_id, email_hash, ip_prefix, succeeded, attempted_at)'
            . " VALUES ('public_return', ?, NULL, ?, ?, UTC_TIMESTAMP())",
        )->execute([$tenantId, $ipPrefix, $succeeded ? 1 : 0]);
    }
}
