<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

/**
 * Lokaler Versand: legt jede Mail als .eml-Datei ab (JJJJMMTT-HHMMSSZ-zufall.eml, Rechte 600, da
 * die Mails Links mit Tokens enthalten). Der Ordner wird bei Bedarf angelegt (Rechte 700).
 */
final readonly class FileMailer implements Mailer
{
    public function __construct(public string $directory)
    {
    }

    public function send(MailMessage $message): string
    {
        $messageId = MimeMessage::newMessageId();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $content = MimeMessage::build($message, $now, $messageId)->toString();

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new MailException(sprintf('Ordner für Mails konnte nicht angelegt werden: %s', $this->directory));
        }

        $file = $this->directory . DIRECTORY_SEPARATOR . $now->format('Ymd-His') . 'Z-' . bin2hex(random_bytes(4)) . '.eml';
        // 'x': nie eine vorhandene Datei überschreiben.
        $handle = @fopen($file, 'xb');
        if ($handle === false) {
            throw new MailException(sprintf('Mail-Datei konnte nicht angelegt werden: %s', $file));
        }
        try {
            chmod($file, 0600);
            if (fwrite($handle, $content) !== strlen($content)) {
                throw new MailException(sprintf('Mail-Datei konnte nicht vollständig geschrieben werden: %s', $file));
            }
        } finally {
            fclose($handle);
        }

        return $messageId;
    }
}
