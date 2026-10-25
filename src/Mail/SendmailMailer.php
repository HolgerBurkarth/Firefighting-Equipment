<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

/**
 * Versand über mail() (auf STRATO: sendmail) mit Envelope-Absender -f feuerwehr-app@prodad.de
 * (Return-Path, wichtig für SPF und Unzustellbarkeitsmeldungen).
 */
final readonly class SendmailMailer implements Mailer
{
    /** @var \Closure(string, string, string, array<string, string>, string): bool */
    private \Closure $mail;

    /**
     * @param (\Closure(string, string, string, array<string, string>, string): bool)|null $mail
     *        Standard mail(); andere nur für Tests
     */
    public function __construct(?\Closure $mail = null)
    {
        $this->mail = $mail ?? static fn (string $to, string $subject, string $body, array $headers, string $params): bool
            => mail($to, $subject, $body, $headers, $params);
    }

    public function send(MailMessage $message): string
    {
        $messageId = MimeMessage::newMessageId();
        $mime = MimeMessage::build($message, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $messageId);

        if (!($this->mail)($mime->to, $mime->subject, $mime->body, $mime->headers, '-f' . MimeMessage::FROM_ADDRESS)) {
            throw new MailException('mail() hat die Nachricht nicht angenommen.');
        }

        return $messageId;
    }
}
