<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail\Smtp;

use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\Mailer;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MimeMessage;

/**
 * Versand über ein SMTP-Postfach (auf STRATO: smtp.strato.de, signiert mit DKIM). Eine Verbindung
 * je Aufruf: EHLO, ggf. STARTTLS und erneut EHLO, AUTH (PLAIN, sonst LOGIN; nur verschlüsselt), dann
 * je Mail MAIL FROM, RCPT TO, DATA, zum Schluss QUIT. sendMany() verschickt mehrere Mails über
 * dieselbe Verbindung (eine Anmeldung); lehnt der Server eine Mail ab, setzt RSET den Vorgang zurück
 * und die nächste folgt. Die Frist (timeout) gilt für die ganze Verbindung.
 *
 * Kopfzeilen und Text baut MimeMessage (wie bei den anderen Transporten); Envelope-Absender und
 * From sind immer feuerwehr-app@prodad.de, der Benutzername ist dieselbe Adresse (ConfigLoader).
 */
final readonly class SmtpMailer implements Mailer
{
    private const string CRLF = "\r\n";

    /**
     * @param string|null $caFile nur für Tests (eigene vertrauenswürdige Stelle); nie aus der Konfiguration
     */
    public function __construct(public SmtpConfig $config, private ?string $caFile = null)
    {
    }

    public function send(MailMessage $message): string
    {
        $result = $this->sendMany([$message])[0];
        if ($result instanceof MailException) {
            throw $result;
        }

        return $result;
    }

    public function sendMany(array $messages, float $deadline = INF): array
    {
        /** @var list<array{MailMessage, string, string}|MailException> $prepared Mail, Message-ID, Daten */
        $prepared = [];
        foreach ($messages as $message) {
            try {
                $messageId = MimeMessage::newMessageId();
                $data = MimeMessage::build($message, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $messageId)->toString();
                $prepared[] = [$message, $messageId, $data];
            } catch (MailException $e) {
                $prepared[] = $e;
            }
        }
        if (array_filter($prepared, is_array(...)) === []) {
            return $prepared;
        }

        /** @var array<int, string|MailException> $results */
        $results = [];
        $connection = null;
        try {
            $timeout = $this->config->timeoutSeconds;
            if (is_finite($deadline)) {
                $timeout = min($timeout, max(0.001, $deadline - microtime(true)));
            }
            $connection = SmtpConnection::open($this->config->host, $this->config->port, $timeout, $this->caFile);
            $this->openSession($connection);

            $last = array_key_last($prepared);
            foreach ($prepared as $i => $item) {
                if ($item instanceof MailException) {
                    $results[$i] = $item;
                    continue;
                }
                if (microtime(true) >= $deadline) {
                    $results[$i] = MailException::timeBudgetExceeded();
                    continue;
                }
                [$message, $messageId, $data] = $item;
                try {
                    self::transmit($connection, $message, $data);
                    $results[$i] = $messageId;
                } catch (MailException $e) {
                    $results[$i] = $e;
                    if ($i === $last) {
                        // Nach einem Fehler bei der letzten Mail kein QUIT mehr, nur schließen.
                        return self::complete($prepared, $results, $e);
                    }
                    // Vorgang zurücksetzen; geht auch das nicht, ist die Verbindung unbrauchbar.
                    self::command($connection, 'RSET', [250], 'RSET');
                }
            }

            // Die Mails sind angenommen; ein Fehler beim Abmelden ändert daran nichts.
            try {
                $connection->write('QUIT' . self::CRLF, 'QUIT');
                $connection->readReply('QUIT');
            } catch (MailException) {
            }

            return self::complete($prepared, $results, null);
        } catch (MailException $e) {
            // Verbindung, Anmeldung oder RSET gescheitert: alle noch offenen Mails erhalten diesen Fehler.
            return self::complete($prepared, $results, $e);
        } finally {
            $connection?->close();
        }
    }

    /**
     * Punkt am Zeilenanfang verdoppeln (RFC 5321, 4.5.2); endet immer mit CRLF.
     */
    public static function dotStuff(string $data): string
    {
        $data = (string) preg_replace('/^\./m', '..', $data);

        return str_ends_with($data, self::CRLF) ? $data : $data . self::CRLF;
    }

    /**
     * Begrüßung, EHLO, ggf. STARTTLS und erneut EHLO, Anmeldung.
     *
     * @throws MailException
     */
    private function openSession(SmtpConnection $connection): void
    {
        if ($this->config->encryption === SmtpEncryption::Ssl) {
            $connection->enableTls();
        }
        self::expect($connection->readReply('Begrüßung'), [220], 'Begrüßung');
        $extensions = self::ehlo($connection);

        if ($this->config->encryption === SmtpEncryption::StartTls) {
            if (!array_key_exists('STARTTLS', $extensions)) {
                throw new MailException(sprintf('Der Server %s bietet kein STARTTLS an; Versand abgebrochen.', $this->config->describe()));
            }
            self::command($connection, 'STARTTLS', [220], 'STARTTLS');
            $connection->enableTls();
            $extensions = self::ehlo($connection);
        }

        $this->authenticate($connection, $extensions['AUTH'] ?? [], $this->config->password);
    }

    /**
     * Eine Mail über die angemeldete Verbindung.
     *
     * @throws MailException
     */
    private static function transmit(SmtpConnection $connection, MailMessage $message, string $data): void
    {
        self::command($connection, 'MAIL FROM:<' . MimeMessage::FROM_ADDRESS . '>', [250], 'MAIL FROM');
        self::command($connection, 'RCPT TO:<' . $message->to->value . '>', [250, 251], 'RCPT TO');
        self::command($connection, 'DATA', [354], 'DATA');
        $connection->write(self::dotStuff($data) . '.' . self::CRLF, 'Nachricht');
        self::expect($connection->readReply('Nachricht'), [250], 'Nachricht');
    }

    /**
     * Ergebnisliste in der Reihenfolge der Mails; was noch fehlt, erhält $failure.
     *
     * @param list<array{MailMessage, string, string}|MailException> $prepared
     * @param array<int, string|MailException>                      $results
     *
     * @return list<string|MailException>
     */
    private static function complete(array $prepared, array $results, ?MailException $failure): array
    {
        $complete = [];
        foreach ($prepared as $i => $item) {
            $complete[] = $results[$i] ?? ($item instanceof MailException ? $item : $failure ?? MailException::timeBudgetExceeded());
        }

        return $complete;
    }

    /**
     * @param list<string> $mechanisms vom Server angebotene AUTH-Verfahren
     *
     * @throws MailException
     */
    private function authenticate(SmtpConnection $connection, array $mechanisms, #[\SensitiveParameter] string $password): void
    {
        if (!$connection->isEncrypted()) {
            throw new MailException('Anmeldung am SMTP-Server nur über eine verschlüsselte Verbindung.');
        }

        $username = $this->config->username;
        if (in_array('PLAIN', $mechanisms, true)) {
            $connection->write('AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password) . self::CRLF, 'AUTH');
            self::expect($connection->readReply('AUTH'), [235], 'AUTH');
        } elseif (in_array('LOGIN', $mechanisms, true)) {
            self::command($connection, 'AUTH LOGIN', [334], 'AUTH');
            self::command($connection, base64_encode($username), [334], 'AUTH');
            $connection->write(base64_encode($password) . self::CRLF, 'AUTH');
            self::expect($connection->readReply('AUTH'), [235], 'AUTH');
        } else {
            throw new MailException(sprintf('Der Server %s bietet keine Anmeldung mit PLAIN oder LOGIN an.', $this->config->describe()));
        }
    }

    /**
     * @return array<string, list<string>>
     *
     * @throws MailException
     */
    private static function ehlo(SmtpConnection $connection): array
    {
        return self::command($connection, 'EHLO ' . MimeMessage::MESSAGE_ID_DOMAIN, [250], 'EHLO')->extensions();
    }

    /**
     * @param list<int> $expected
     *
     * @throws MailException
     */
    private static function command(SmtpConnection $connection, string $line, array $expected, string $step): SmtpReply
    {
        $connection->write($line . self::CRLF, $step);

        return self::expect($connection->readReply($step), $expected, $step);
    }

    /**
     * @param list<int> $expected
     *
     * @throws MailException
     */
    private static function expect(SmtpReply $reply, array $expected, string $step): SmtpReply
    {
        if (!in_array($reply->code, $expected, true)) {
            throw new MailException(sprintf('SMTP-Fehler bei %s: %s', $step, $reply->summary()));
        }

        return $reply;
    }
}
