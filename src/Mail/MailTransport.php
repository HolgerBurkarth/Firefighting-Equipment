<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

/**
 * Versandweg (mail.transport): sendmail über mail(), SMTP-Postfach (mail.smtp) oder lokal als
 * .eml-Datei.
 */
enum MailTransport: string
{
    case Sendmail = 'sendmail';
    case Smtp = 'smtp';
    case File = 'file';
}
