<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail\Smtp;

use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\Mailer;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MimeMessage;

/**
 * Versand über ein SMTP-Postfach (auf STRATO: smtp.strato.de, signiert mit DKIM). Eine Verbindung
 * je Mail: EHLO, ggf. STARTTLS und erneut EHLO, AUTH (PLAIN, sonst LOGIN; nur verschlüsselt),
 * MAIL FROM, RCPT TO, DATA, QUIT.
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
        $messageId = MimeMessage::newMessageId();
        $data = MimeMessage::build($message, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $messageId)->toString();

        $connection = SmtpConnection::open($this->config->host, $this->config->port, $this->config->timeoutSeconds, $this->caFile);
        try {
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

            self::command($connection, 'MAIL FROM:<' . MimeMessage::FROM_ADDRESS . '>', [250], 'MAIL FROM');
            self::command($connection, 'RCPT TO:<' . $message->to->value . '>', [250, 251], 'RCPT TO');
            self::command($connection, 'DATA', [354], 'DATA');
            $connection->write(self::dotStuff($data) . '.' . self::CRLF, 'Nachricht');
            self::expect($connection->readReply('Nachricht'), [250], 'Nachricht');

            // Die Mail ist angenommen; ein Fehler beim Abmelden ändert daran nichts.
            try {
                $connection->write('QUIT' . self::CRLF, 'QUIT');
                $connection->readReply('QUIT');
            } catch (MailException) {
            }
        } finally {
            $connection->close();
        }

        return $messageId;
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
