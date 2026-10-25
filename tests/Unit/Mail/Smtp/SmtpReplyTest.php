<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Mail\Smtp;

use FirefightingEquipment\Mail\Smtp\SmtpReply;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SmtpReply::class)]
final class SmtpReplyTest extends TestCase
{
    public function testExtensionsOfEhloReply(): void
    {
        $reply = new SmtpReply(250, ['smtp.example', 'PIPELINING', 'auth plain LOGIN', 'AUTH=LOGIN XOAUTH2', 'STARTTLS', 'SIZE 1000']);

        self::assertSame([
            'PIPELINING' => [],
            'AUTH' => ['PLAIN', 'LOGIN', 'XOAUTH2'],
            'STARTTLS' => [],
            'SIZE' => ['1000'],
        ], $reply->extensions());
    }

    public function testSummaryMasksAddressesAndControlCharacters(): void
    {
        $reply = new SmtpReply(550, ["5.1.1 <erika@example.org>: Recipient\x1b[31m rejected", 'see user.name+tag@sub.example.org']);

        self::assertSame('550 5.1.1 <<Adresse>>: Recipient [31m rejected see <Adresse>', $reply->summary());
    }

    public function testSummaryIsShortened(): void
    {
        $summary = new SmtpReply(554, [str_repeat('ä', 300)])->summary();

        self::assertLessThan(220, strlen($summary));
        self::assertStringEndsWith(' …', $summary);
        self::assertTrue(mb_check_encoding($summary, 'UTF-8'), 'Nicht mitten im Zeichen gekürzt');
    }
}
