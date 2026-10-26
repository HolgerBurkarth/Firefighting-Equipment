<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

/**
 * Versendet Mails. Handler und CLI senden über Notifier (protokolliert Fehler, reicht sie nicht
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

    /**
     * Mehrere Mails in einem Durchgang (SMTP: eine Verbindung, eine Anmeldung). Ein Fehler betrifft
     * nur die jeweilige Mail, soweit der Transport weiterarbeiten kann. Nach $deadline wird keine
     * weitere Mail mehr begonnen; die übrigen erhalten eine MailException.
     *
     * @param list<MailMessage> $messages
     * @param float             $deadline Zeitpunkt wie microtime(true); INF = ohne Grenze
     *
     * @return list<string|MailException> je Mail (gleiche Reihenfolge) die Message-ID oder der Fehler
     */
    public function sendMany(array $messages, float $deadline = INF): array;
}
