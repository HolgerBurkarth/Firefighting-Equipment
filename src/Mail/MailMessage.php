<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

use FirefightingEquipment\User\EmailAddress;

/**
 * Eine Klartext-Mail (UTF-8) an einen Empfänger. Absender ist immer die Anwendung (MimeMessage).
 *
 * Schutz gegen Header-Injection: Empfänger, Empfängername und Betreff dürfen keine Steuerzeichen
 * (CR, LF, NUL, Tab, …) enthalten, sonst wird abgebrochen. Die Adresse muss aus ASCII bestehen:
 * Ohne intl (IDN) und SMTPUTF8 lassen sich andere Adressen nicht sicher kodieren.
 */
final readonly class MailMessage
{
    /** Hält die Zeile "Subject: …" auch kodiert deutlich unter 998 Zeichen. */
    public const int MAX_SUBJECT_LENGTH = 200;

    private const string CONTROL = '/[\p{Cc}\x{2028}\x{2029}]/u';

    /**
     * @throws MailException
     */
    public function __construct(
        public EmailAddress $to,
        public ?string $toName,
        public string $subject,
        /** Klartext; Zeilenenden beliebig (werden beim Senden vereinheitlicht). */
        public string $text,
    ) {
        if (preg_match('/^[\x21-\x7E]+$/D', $to->value) !== 1) {
            throw new MailException('Die Empfängeradresse enthält Zeichen außerhalb von ASCII; Versand nicht möglich.');
        }
        self::assertHeaderText($toName ?? '', 'Der Empfängername');
        self::assertHeaderText($subject, 'Der Betreff');
        if (trim($subject) === '' || mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new MailException(sprintf('Der Betreff muss 1 bis %d Zeichen lang sein.', self::MAX_SUBJECT_LENGTH));
        }
        if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[^\P{Cc}\t\r\n]/u', $text) === 1) {
            throw new MailException('Der Text der Mail ist kein gültiger UTF-8-Text oder enthält Steuerzeichen.');
        }
    }

    /**
     * @throws MailException
     */
    private static function assertHeaderText(string $value, string $what): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new MailException($what . ' ist kein gültiger UTF-8-Text.');
        }
        if (preg_match(self::CONTROL, $value) === 1) {
            throw new MailException($what . ' enthält Steuerzeichen (z. B. Zeilenumbrüche); Versand abgebrochen.');
        }
    }
}
