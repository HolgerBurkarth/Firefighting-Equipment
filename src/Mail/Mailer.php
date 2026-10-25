<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

/**
 * Versendet eine Mail. Handler und CLI senden über Notifier (protokolliert Fehler, reicht sie nicht
 * weiter); nur bin/mail-test.php ruft send() direkt auf.
 */
interface Mailer
{
    /**
     * @return string Message-ID der versendeten Mail
     *
     * @throws MailException
     */
    public function send(MailMessage $message): string;
}
