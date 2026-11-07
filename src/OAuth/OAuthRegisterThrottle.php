<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

use FirefightingEquipment\Auth\AttemptWindow;

/**
 * Bremse für die Registrierung von OAuth-Clients (fe_auth_attempts, action = oauth_register, ohne
 * E-Mail): höchstens 20 angenommene Registrierungen je IP-Präfix (über alle Städte) pro Stunde.
 * Abgewiesene Anfragen werden nicht gespeichert.
 */
final readonly class OAuthRegisterThrottle
{
    public const int WINDOW_SECONDS = 3600;
    public const int MAX_PER_IP = 20;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return int|null Sekunden bis zur nächsten erlaubten Registrierung; null = nicht gesperrt
     */
    public function retryAfter(string $ipPrefix): ?int
    {
        return AttemptWindow::secondsUntilBelowLimit(
            $this->pdo,
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), attempted_at + INTERVAL ? SECOND) FROM fe_auth_attempts'
            . " WHERE ip_prefix = ? AND action = 'oauth_register' AND succeeded = 1"
            . ' AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? SECOND'
            . ' ORDER BY attempted_at DESC, id DESC LIMIT ' . self::MAX_PER_IP,
            [self::WINDOW_SECONDS, $ipPrefix, self::WINDOW_SECONDS],
            self::MAX_PER_IP,
        );
    }

    public function record(int $tenantId, string $ipPrefix): void
    {
        $this->pdo->prepare(
            'INSERT INTO fe_auth_attempts (action, tenant_id, email_hash, ip_prefix, succeeded, attempted_at)'
            . " VALUES ('oauth_register', ?, NULL, ?, 1, UTC_TIMESTAMP())",
        )->execute([$tenantId, $ipPrefix]);
    }
}
