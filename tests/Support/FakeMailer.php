<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\Mailer;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MimeMessage;

/**
 * Mailer für Tests: sammelt die Mails, statt sie zu versenden. Baut jede Mail wie ein echter
 * Transport (MimeMessage), damit Kodierfehler auffallen. Mit $fail schlägt jeder Versand fehl.
 */
final class FakeMailer implements Mailer
{
    /** @var list<MailMessage> */
    public array $sent = [];

    public function __construct(public bool $fail = false)
    {
    }

    public function send(MailMessage $message): string
    {
        $messageId = MimeMessage::newMessageId();
        MimeMessage::build($message, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $messageId);
        if ($this->fail) {
            throw new MailException('Versand absichtlich fehlgeschlagen (Test).');
        }
        $this->sent[] = $message;

        return $messageId;
    }

    /**
     * Die einzige versendete Mail (schlägt fehl, wenn es nicht genau eine ist).
     */
    public function only(): MailMessage
    {
        if (count($this->sent) !== 1) {
            throw new \LogicException(sprintf('Genau eine Mail erwartet, versendet: %d.', count($this->sent)));
        }

        return $this->sent[0];
    }
}
