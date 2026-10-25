<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Mail;

use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MimeMessage;
use FirefightingEquipment\Mail\SendmailMailer;
use FirefightingEquipment\User\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SendmailMailer::class)]
#[UsesClass(MailException::class)]
#[UsesClass(MailMessage::class)]
#[UsesClass(MimeMessage::class)]
#[UsesClass(EmailAddress::class)]
final class SendmailMailerTest extends TestCase
{
    public function testCallsMailWithEncodedPartsAndEnvelopeSender(): void
    {
        $calls = [];
        $mailer = new SendmailMailer(static function (string $to, string $subject, string $body, array $headers, string $params) use (&$calls): bool {
            $calls[] = [$to, $subject, $body, $headers, $params];

            return true;
        });

        $messageId = $mailer->send(new MailMessage(EmailAddress::fromString('erika@example.org'), 'Jürgen', 'Grüße', "Hallo\nWelt"));

        self::assertCount(1, $calls);
        [$to, $subject, $body, $headers, $params] = $calls[0];
        self::assertSame('=?UTF-8?B?' . base64_encode('Jürgen') . '?= <erika@example.org>', $to);
        self::assertSame('=?UTF-8?B?' . base64_encode('Grüße') . '?=', $subject);
        self::assertSame("Hallo\r\nWelt\r\n", quoted_printable_decode($body));
        self::assertSame('-ffeuerwehr-app@prodad.de', $params);
        self::assertSame($messageId, $headers['Message-ID']);
        self::assertSame('auto-generated', $headers['Auto-Submitted']);
        self::assertArrayNotHasKey('To', $headers, 'To und Subject übergibt mail() selbst');
        self::assertArrayNotHasKey('Subject', $headers);
        self::assertEqualsWithDelta(time(), strtotime($headers['Date']), 5);
    }

    public function testRefusedMailIsReportedWithoutContent(): void
    {
        $mailer = new SendmailMailer(static fn (): bool => false);

        try {
            $mailer->send(new MailMessage(EmailAddress::fromString('erika@example.org'), null, 'Betreff', 'geheimer-link-123'));
            self::fail('MailException erwartet.');
        } catch (MailException $e) {
            self::assertSame('mail() hat die Nachricht nicht angenommen.', $e->getMessage());
        }
    }
}
