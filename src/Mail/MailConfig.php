<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

use FirefightingEquipment\Mail\Smtp\SmtpConfig;
use FirefightingEquipment\Mail\Smtp\SmtpMailer;

/**
 * Optionale Gruppe 'mail' der Konfiguration. Standard (Produktion): sendmail.
 *
 * 'mail.smtp' wird geprüft, sobald die Gruppe vorhanden ist, aber nur bei transport = smtp verwendet
 * (lokal lässt sich so zwischen file und smtp umschalten).
 */
final readonly class MailConfig
{
    public const string DEFAULT_FILE_DIR = 'var/mail';

    public function __construct(
        public MailTransport $transport = MailTransport::Sendmail,
        /** Nur bei transport = file: Ordner für .eml-Dateien, relativ zum Projektordner oder absolut. */
        public string $fileDir = self::DEFAULT_FILE_DIR,
        /** Gruppe 'mail.smtp'; bei transport = smtp immer vorhanden (ConfigLoader). */
        public ?SmtpConfig $smtp = null,
    ) {
    }

    /**
     * Ordner für .eml-Dateien als absoluter Pfad.
     */
    public function fileDirectory(string $projectDir): string
    {
        if (preg_match('#^([/\\\\]|[A-Za-z]:[/\\\\])#', $this->fileDir) === 1) {
            return $this->fileDir;
        }

        return rtrim($projectDir, '/\\') . DIRECTORY_SEPARATOR . $this->fileDir;
    }

    /**
     * @throws MailException wenn bei transport = smtp die Gruppe 'mail.smtp' fehlt
     */
    public function createMailer(string $projectDir): Mailer
    {
        return match ($this->transport) {
            MailTransport::Sendmail => new SendmailMailer(),
            MailTransport::Smtp => new SmtpMailer($this->smtp ?? throw new MailException("Gruppe 'mail.smtp' fehlt.")),
            MailTransport::File => new FileMailer($this->fileDirectory($projectDir)),
        };
    }
}
