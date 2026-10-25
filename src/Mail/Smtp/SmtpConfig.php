<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail\Smtp;

/**
 * Gruppe 'mail.smtp' der Konfiguration (geprüft von ConfigLoader). Das Zertifikat des Servers wird
 * immer geprüft; dafür gibt es bewusst keine Einstellung.
 *
 * Das Passwort erscheint weder in var_dump()/print_r() (__debugInfo) noch in Stack-Traces
 * (#[\SensitiveParameter]).
 */
final readonly class SmtpConfig
{
    public const string DEFAULT_HOST = 'smtp.strato.de';
    public const int DEFAULT_PORT = 465;
    public const float DEFAULT_TIMEOUT_SECONDS = 10.0;
    public const float MAX_TIMEOUT_SECONDS = 60.0;

    public function __construct(
        public string $username,
        #[\SensitiveParameter] public string $password,
        public string $host = self::DEFAULT_HOST,
        public int $port = self::DEFAULT_PORT,
        public SmtpEncryption $encryption = SmtpEncryption::Ssl,
        /** Höchstdauer des ganzen Versands (Verbindung, TLS, Dialog) in Sekunden. */
        public float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * Server für Ausgaben, z. B. "smtp.strato.de:465 (ssl)".
     */
    public function describe(): string
    {
        return sprintf('%s:%d (%s)', $this->host, $this->port, $this->encryption->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'username' => $this->username,
            'password' => '***',
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'timeoutSeconds' => $this->timeoutSeconds,
        ];
    }
}
