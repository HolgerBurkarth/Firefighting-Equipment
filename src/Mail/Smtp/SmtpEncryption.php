<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail\Smtp;

/**
 * Verschlüsselung der SMTP-Verbindung (mail.smtp.encryption). Unverschlüsselt gibt es bewusst nicht.
 */
enum SmtpEncryption: string
{
    /** Implizites TLS ab dem ersten Byte (Port 465). */
    case Ssl = 'ssl';

    /** Klartext-Begrüßung, dann STARTTLS (Port 587); ohne STARTTLS wird abgebrochen. */
    case StartTls = 'starttls';
}
