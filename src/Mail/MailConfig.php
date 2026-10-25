<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

/**
 * Optionale Gruppe 'mail' der Konfiguration. Standard (Produktion): sendmail.
 */
final readonly class MailConfig
{
    public const string DEFAULT_FILE_DIR = 'var/mail';

    public function __construct(
        public MailTransport $transport = MailTransport::Sendmail,
        /** Nur bei transport = file: Ordner für .eml-Dateien, relativ zum Projektordner oder absolut. */
        public string $fileDir = self::DEFAULT_FILE_DIR,
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

    public function createMailer(string $projectDir): Mailer
    {
        return match ($this->transport) {
            MailTransport::Sendmail => new SendmailMailer(),
            MailTransport::File => new FileMailer($this->fileDirectory($projectDir)),
        };
    }
}
