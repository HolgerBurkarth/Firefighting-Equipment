<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

/**
 * sendMany() für Transporte ohne gemeinsame Verbindung (mail(), Dateien, Tests): Mail für Mail
 * über send(), bis $deadline.
 */
trait SendsEachMessage
{
    /**
     * @param list<MailMessage> $messages
     *
     * @return list<string|MailException>
     */
    public function sendMany(array $messages, float $deadline = INF): array
    {
        $results = [];
        foreach ($messages as $message) {
            if (microtime(true) >= $deadline) {
                $results[] = MailException::timeBudgetExceeded();
                continue;
            }
            try {
                $results[] = $this->send($message);
            } catch (MailException $e) {
                $results[] = $e;
            }
        }

        return $results;
    }
}
