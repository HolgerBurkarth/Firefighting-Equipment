<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Bremse für Registrierung und E-Mail-Bestätigung (fe_auth_attempts, nur unter Städten):
 * - Registrierung (action = register): höchstens 10 ungültige Einladungscodes je IP-Präfix in
 *   15 Minuten; angenommene Registrierungen (auch mit schon vergebener E-Mail, beide verschicken
 *   eine Mail) höchstens 3 je E-Mail und Stadt sowie 30 je IP-Präfix (über alle Städte) pro
 *   Stunde. Das IP-Limit ist hoch, weil sich beim Dienstabend viele aus dem WLAN der Wache (ein
 *   Präfix) registrieren.
 * - Bestätigen (action = verify_email, ohne E-Mail): höchstens 10 Fehlversuche (ungültiger Link)
 *   je IP-Präfix in 15 Minuten.
 * - Link neu anfordern (action = verify_resend): jede angenommene Anfrage zählt, auch für
 *   unbekannte Adressen; höchstens 3 je E-Mail und Stadt und 20 je IP-Präfix pro Stunde.
 * Abgewiesene Anfragen werden nicht gespeichert, sonst ließe sich die Sperre beliebig verlängern.
 */
final readonly class RegistrationThrottle
{
    public const int CODE_FAILURE_WINDOW_SECONDS = 900;
    public const int MAX_CODE_FAILURES_PER_IP = 10;
    public const int REGISTER_WINDOW_SECONDS = 3600;
    public const int MAX_REGISTRATIONS_PER_ACCOUNT = 3;
    public const int MAX_REGISTRATIONS_PER_IP = 30;
    public const int VERIFY_WINDOW_SECONDS = 900;
    public const int MAX_VERIFY_FAILURES_PER_IP = 10;
    public const int RESEND_WINDOW_SECONDS = 3600;
    public const int MAX_RESENDS_PER_ACCOUNT = 3;
    public const int MAX_RESENDS_PER_IP = 20;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return int|null Sekunden bis zur nächsten erlaubten Registrierung; null = nicht gesperrt
     */
    public function registerRetryAfter(int $tenantId, string $emailHash, string $ipPrefix): ?int
    {
        return AttemptWindow::combine(
            $this->perIp('register', 0, $ipPrefix, self::CODE_FAILURE_WINDOW_SECONDS, self::MAX_CODE_FAILURES_PER_IP),
            $this->perAccount('register', $tenantId, $emailHash, self::REGISTER_WINDOW_SECONDS, self::MAX_REGISTRATIONS_PER_ACCOUNT),
            $this->perIp('register', 1, $ipPrefix, self::REGISTER_WINDOW_SECONDS, self::MAX_REGISTRATIONS_PER_IP),
        );
    }

    /**
     * @param bool $accepted false = ungültiger Einladungscode
     */
    public function recordRegister(int $tenantId, string $emailHash, string $ipPrefix, bool $accepted): void
    {
        $this->record('register', $tenantId, $emailHash, $ipPrefix, $accepted);
    }

    /**
     * @return int|null Sekunden bis zum nächsten erlaubten Bestätigen; null = nicht gesperrt
     */
    public function verifyRetryAfter(string $ipPrefix): ?int
    {
        return $this->perIp('verify_email', 0, $ipPrefix, self::VERIFY_WINDOW_SECONDS, self::MAX_VERIFY_FAILURES_PER_IP);
    }

    public function recordVerify(int $tenantId, string $ipPrefix, bool $succeeded): void
    {
        $this->record('verify_email', $tenantId, null, $ipPrefix, $succeeded);
    }

    /**
     * @return int|null Sekunden bis zur nächsten erlaubten Anforderung; null = nicht gesperrt
     */
    public function resendRetryAfter(int $tenantId, string $emailHash, string $ipPrefix): ?int
    {
        return AttemptWindow::combine(
            $this->perAccount('verify_resend', $tenantId, $emailHash, self::RESEND_WINDOW_SECONDS, self::MAX_RESENDS_PER_ACCOUNT),
            $this->perIp('verify_resend', 1, $ipPrefix, self::RESEND_WINDOW_SECONDS, self::MAX_RESENDS_PER_IP),
        );
    }

    /**
     * Speichert eine angenommene Anforderung (succeeded = 1; ob eine Mail ging, steht nirgends).
     */
    public function recordResend(int $tenantId, string $emailHash, string $ipPrefix): void
    {
        $this->record('verify_resend', $tenantId, $emailHash, $ipPrefix, true);
    }

    /**
     * Angenommene Versuche (succeeded = 1) je E-Mail und Stadt.
     */
    private function perAccount(string $action, int $tenantId, string $emailHash, int $window, int $limit): ?int
    {
        return AttemptWindow::secondsUntilBelowLimit(
            $this->pdo,
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), attempted_at + INTERVAL ? SECOND) FROM fe_auth_attempts'
            . ' WHERE action = ? AND tenant_id = ? AND email_hash = ? AND succeeded = 1'
            . ' AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? SECOND'
            . ' ORDER BY attempted_at DESC, id DESC LIMIT ' . $limit,
            [$window, $action, $tenantId, $emailHash, $window],
            $limit,
        );
    }

    /**
     * Versuche mit Ergebnis $succeeded je IP-Präfix über alle Städte.
     */
    private function perIp(string $action, int $succeeded, string $ipPrefix, int $window, int $limit): ?int
    {
        return AttemptWindow::secondsUntilBelowLimit(
            $this->pdo,
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), attempted_at + INTERVAL ? SECOND) FROM fe_auth_attempts'
            . ' WHERE ip_prefix = ? AND action = ? AND succeeded = ?'
            . ' AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? SECOND'
            . ' ORDER BY attempted_at DESC, id DESC LIMIT ' . $limit,
            [$window, $ipPrefix, $action, $succeeded, $window],
            $limit,
        );
    }

    private function record(string $action, int $tenantId, ?string $emailHash, string $ipPrefix, bool $succeeded): void
    {
        $this->pdo->prepare(
            'INSERT INTO fe_auth_attempts (action, tenant_id, email_hash, ip_prefix, succeeded, attempted_at)'
            . ' VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        )->execute([$action, $tenantId, $emailHash, $ipPrefix, $succeeded ? 1 : 0]);
    }
}
