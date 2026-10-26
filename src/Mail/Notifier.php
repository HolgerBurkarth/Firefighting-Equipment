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
            $this->reportFailure($purpose, $userId, $e);

            return false;
        }
    }

    /**
     * Mehrere Mails in einem Durchgang (SMTP: eine Verbindung, siehe Mailer::sendMany). Jede Mail wird
     * einzeln gebaut; Fehler (beim Bauen oder Versand, auch Überschreiten von $deadline) werden je
     * Mail wie bei send() protokolliert.
     *
     * @param list<array{?int, \Closure(): MailMessage}> $mails Konto-ID (für das Protokoll) und Aufbau
     * @param float                                      $deadline Zeitpunkt wie microtime(true)
     *
     * @return int Anzahl übergebener Mails
     */
    public function sendMany(string $purpose, array $mails, float $deadline = INF): int
    {
        $messages = [];
        $userIds = [];
        foreach ($mails as [$userId, $build]) {
            try {
                $messages[] = $build();
                $userIds[] = $userId;
            } catch (\Throwable $e) {
                $this->reportFailure($purpose, $userId, $e);
            }
        }
        if ($messages === []) {
            return 0;
        }

        try {
            $results = $this->mailer->sendMany($messages, $deadline);
        } catch (\Throwable $e) {
            $results = array_fill(0, count($messages), $e);
        }

        $sent = 0;
        foreach ($results as $i => $result) {
            if ($result instanceof \Throwable) {
                $this->reportFailure($purpose, $userIds[$i], $result);
            } else {
                ++$sent;
            }
        }

        return $sent;
    }

    private function reportFailure(string $purpose, ?int $userId, \Throwable $e): void
    {
        $this->errors->report(
            sprintf('mail_failed (%s%s)', $purpose, $userId === null ? '' : ', Konto #' . $userId),
            $e,
        );
    }
}
