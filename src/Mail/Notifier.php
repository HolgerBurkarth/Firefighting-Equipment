<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

use FirefightingEquipment\Http\ErrorReporter;

/**
 * Sendet Benachrichtigungen, ohne dass ein Fehler den Aufrufer erreicht: Fehler (auch beim Bauen
 * der Mail, z. B. eine nicht nutzbare Konfiguration) gehen über ErrorReporter ins error_log, mit
 * Zweck und Konto-ID, nie mit Empfänger, Text, Link oder Token.
 */
final readonly class Notifier
{
    public function __construct(private Mailer $mailer, private ErrorReporter $errors)
    {
    }

    /**
     * @param string               $purpose z. B. password_reset (nur für das Protokoll)
     * @param \Closure(): MailMessage $build
     *
     * @return bool ob die Mail übergeben wurde
     */
    public function send(string $purpose, ?int $userId, \Closure $build): bool
    {
        try {
            $this->mailer->send($build());

            return true;
        } catch (\Throwable $e) {
            $this->errors->report(
                sprintf('mail_failed (%s%s)', $purpose, $userId === null ? '' : ', Konto #' . $userId),
                $e,
            );

            return false;
        }
    }
}
