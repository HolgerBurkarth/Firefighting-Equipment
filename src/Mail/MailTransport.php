<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

/**
 * Versandweg (mail.transport): sendmail über mail() oder lokal als .eml-Datei.
 */
enum MailTransport: string
{
    case Sendmail = 'sendmail';
    case File = 'file';
}
