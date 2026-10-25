<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail\Smtp;

use FirefightingEquipment\Mail\MailException;

/**
 * TCP-Verbindung zu einem SMTP-Server mit TLS und einer Frist für den ganzen Dialog.
 *
 * TLS: Zertifikat und Hostname werden immer geprüft (verify_peer, verify_peer_name, keine
 * selbstsignierten Zertifikate, mindestens TLS 1.2). Abschalten lässt sich das nicht; $caFile
 * ersetzt nur die vertrauenswürdigen Stellen (nur für Tests).
 *
 * Fehlermeldungen enthalten nie gesendete Daten (Anmeldung, Text der Mail), nur Schritt, Server
 * und die bereinigte Antwort bzw. PHP-Warnung (SmtpReply::sanitize).
 */
final class SmtpConnection
{
    private const int MAX_LINE_BYTES = 2048;
    private const int MAX_REPLY_LINES = 100;
    private const int CRYPTO_METHOD = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /**
     * @param resource $socket
     */
    private function __construct(
        private mixed $socket,
        private readonly string $server,
        private readonly float $deadline,
        private readonly float $timeoutSeconds,
    ) {
    }

    /**
     * @param string|null $caFile nur für Tests: eigene vertrauenswürdige Stelle (PEM); sonst die des Systems
     *
     * @throws MailException
     */
    public static function open(string $host, int $port, float $timeoutSeconds, ?string $caFile = null): self
    {
        $deadline = self::now() + $timeoutSeconds;
        $server = $host . ':' . $port;
        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'crypto_method' => self::CRYPTO_METHOD,
            'disable_compression' => true,
        ];
        if ($caFile !== null) {
            $options['cafile'] = $caFile;
        }

        $errno = 0;
        $errstr = '';
        [$socket, $warning] = self::capturingWarnings(static fn (): mixed => stream_socket_client(
            'tcp://' . $server,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $options]),
        ));
        if (!is_resource($socket)) {
            throw new MailException(sprintf(
                'Verbindung zu %s fehlgeschlagen: %s',
                $server,
                SmtpReply::sanitize($errstr !== '' ? $errstr : ($warning ?? 'unbekannter Fehler')),
            ));
        }

        return new self($socket, $server, $deadline, $timeoutSeconds);
    }

    /**
     * Schaltet TLS ein (sofort bei implizitem TLS, nach STARTTLS sonst) und prüft dabei das Zertifikat.
     *
     * @throws MailException
     */
    public function enableTls(): void
    {
        $this->applyTimeout('TLS');
        [$result, $warning] = self::capturingWarnings(fn (): mixed => stream_socket_enable_crypto($this->socket, true, self::CRYPTO_METHOD));
        if ($result !== true) {
            throw new MailException(sprintf(
                'TLS-Verbindung zu %s fehlgeschlagen (Zertifikat oder Verschlüsselung): %s',
                $this->server,
                SmtpReply::sanitize($this->timedOut() ? 'Zeitüberschreitung' : ($warning ?? 'unbekannter Fehler')),
            ));
        }
    }

    public function isEncrypted(): bool
    {
        return isset(stream_get_meta_data($this->socket)['crypto']);
    }

    /**
     * Liest eine (ggf. mehrzeilige) Antwort.
     *
     * @throws MailException
     */
    public function readReply(string $step): SmtpReply
    {
        $lines = [];
        do {
            $this->applyTimeout($step);
            $line = @fgets($this->socket, self::MAX_LINE_BYTES);
            if ($line === false) {
                throw $this->timedOut()
                    ? $this->timeoutException($step)
                    : new MailException(sprintf('Verbindung zu %s vom Server beendet (Schritt %s).', $this->server, $step));
            }
            if (!str_ends_with($line, "\n") || count($lines) >= self::MAX_REPLY_LINES) {
                throw new MailException(sprintf('Ungültige Antwort von %s (Schritt %s): zu lang.', $this->server, $step));
            }
            if (preg_match('/^([1-5][0-9]{2})([ -])(.*)$/sD', rtrim($line, "\r\n"), $match) !== 1) {
                throw new MailException(sprintf(
                    'Ungültige Antwort von %s (Schritt %s): %s',
                    $this->server,
                    $step,
                    SmtpReply::sanitize($line),
                ));
            }
            if (isset($code) && (int) $match[1] !== $code) {
                throw new MailException(sprintf('Ungültige Antwort von %s (Schritt %s): wechselnder Code.', $this->server, $step));
            }
            $code = (int) $match[1];
            $lines[] = $match[3];
        } while ($match[2] === '-');

        return new SmtpReply($code, $lines);
    }

    /**
     * @param string $data vollständige Zeile(n) mit CRLF; kann Zugangsdaten enthalten
     *
     * @throws MailException
     */
    public function write(#[\SensitiveParameter] string $data, string $step): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $this->applyTimeout($step);
            $written = @fwrite($this->socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw $this->timedOut()
                    ? $this->timeoutException($step)
                    : new MailException(sprintf('Verbindung zu %s unterbrochen (Schritt %s).', $this->server, $step));
            }
            $offset += $written;
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    /**
     * @throws MailException
     */
    private function applyTimeout(string $step): void
    {
        $remaining = $this->deadline - self::now();
        if ($remaining <= 0) {
            throw $this->timeoutException($step);
        }
        $seconds = (int) floor($remaining);
        stream_set_timeout($this->socket, $seconds, (int) (($remaining - $seconds) * 1e6));
    }

    private function timedOut(): bool
    {
        return (bool) (stream_get_meta_data($this->socket)['timed_out'] ?? false) || self::now() >= $this->deadline;
    }

    private function timeoutException(string $step): MailException
    {
        return new MailException(sprintf(
            'Zeitüberschreitung bei %s (Schritt %s, Zeitlimit %s s).',
            $this->server,
            $step,
            rtrim(rtrim(number_format($this->timeoutSeconds, 1, '.', ''), '0'), '.'),
        ));
    }

    /**
     * Führt $call aus und liefert das Ergebnis und die letzte PHP-Warnung (ohne Funktionsnamen).
     *
     * @return array{mixed, ?string}
     */
    private static function capturingWarnings(\Closure $call): array
    {
        $warning = null;
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = (string) preg_replace('/^[\w:]+\(\): /', '', $message);

            return true;
        });
        try {
            return [$call(), $warning];
        } finally {
            restore_error_handler();
        }
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
