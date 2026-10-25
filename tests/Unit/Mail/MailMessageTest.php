<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Mail;

use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\User\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Schutz gegen Header-Injection: Steuerzeichen in Empfänger, Name oder Betreff brechen ab.
 */
#[CoversClass(MailMessage::class)]
#[CoversClass(MailException::class)]
#[UsesClass(EmailAddress::class)]
final class MailMessageTest extends TestCase
{
    public function testValidMessage(): void
    {
        $message = new MailMessage(EmailAddress::fromString('erika@example.org'), 'Jürgen Weiß', 'Grüße', "Zeile 1\r\nZeile 2\n\tEingerückt");

        self::assertSame('erika@example.org', $message->to->value);
        self::assertSame('Jürgen Weiß', $message->toName);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injections(): iterable
    {
        yield 'LF' => ["Hallo\nBcc: opfer@example.org"];
        yield 'CR' => ["Hallo\rBcc: opfer@example.org"];
        yield 'CRLF' => ["Hallo\r\nBcc: opfer@example.org"];
        yield 'NUL' => ["Hallo\0"];
        yield 'Tab' => ["Hallo\tWelt"];
        yield 'Unicode-Zeilentrenner' => ["Hallo\u{2028}Welt"];
        yield 'NEL' => ["Hallo\u{0085}Welt"];
    }

    #[DataProvider('injections')]
    public function testControlCharacterInSubjectIsRejected(string $value): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Der Betreff enthält Steuerzeichen');

        new MailMessage(EmailAddress::fromString('erika@example.org'), null, $value, 'Text');
    }

    #[DataProvider('injections')]
    public function testControlCharacterInNameIsRejected(string $value): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Der Empfängername enthält Steuerzeichen');

        new MailMessage(EmailAddress::fromString('erika@example.org'), $value, 'Betreff', 'Text');
    }

    public function testRecipientOutsideAsciiIsRejected(): void
    {
        // EmailAddress erlaubt Unicode; ohne SMTPUTF8 und IDN (intl) ist das nicht sicher kodierbar.
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('außerhalb von ASCII');

        new MailMessage(EmailAddress::fromString('straße@example.org'), null, 'Betreff', 'Text');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'leerer Betreff' => ['subject', ' '];
        yield 'zu langer Betreff' => ['subject', str_repeat('a', MailMessage::MAX_SUBJECT_LENGTH + 1)];
        yield 'Betreff kein UTF-8' => ['subject', "\xFF"];
        yield 'Name kein UTF-8' => ['name', "\xC3"];
        yield 'Text kein UTF-8' => ['text', "Gr\xFC\xDFe"];
        yield 'NUL im Text' => ['text', "Hallo\0"];
        yield 'Escape im Text' => ['text', "Hallo\e[31m"];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(string $field, string $value): void
    {
        $this->expectException(MailException::class);

        new MailMessage(
            EmailAddress::fromString('erika@example.org'),
            $field === 'name' ? $value : null,
            $field === 'subject' ? $value : 'Betreff',
            $field === 'text' ? $value : 'Text',
        );
    }

    public function testSubjectWithMaximalLengthIsAccepted(): void
    {
        $subject = str_repeat('ä', MailMessage::MAX_SUBJECT_LENGTH);

        self::assertSame($subject, new MailMessage(EmailAddress::fromString('erika@example.org'), null, $subject, 'Text')->subject);
    }
}
