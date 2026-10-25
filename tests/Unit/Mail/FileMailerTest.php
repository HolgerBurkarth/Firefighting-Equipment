<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Mail;

use FirefightingEquipment\Mail\FileMailer;
use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MimeMessage;
use FirefightingEquipment\Tests\Support\TempDirectory;
use FirefightingEquipment\User\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileMailer::class)]
#[UsesClass(MailException::class)]
#[UsesClass(MailMessage::class)]
#[UsesClass(MimeMessage::class)]
#[UsesClass(EmailAddress::class)]
final class FileMailerTest extends TestCase
{
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = new TempDirectory();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testWritesCompleteMessageAsEmlFile(): void
    {
        $messageId = new FileMailer($this->dir->path)->send(self::message());

        $files = $this->emlFiles($this->dir->path);
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/^\d{8}-\d{6}Z-[0-9a-f]{8}\.eml$/D', basename($files[0]));

        $content = (string) file_get_contents($files[0]);
        [$head, $body] = explode("\r\n\r\n", $content, 2);
        self::assertStringStartsWith("To: =?UTF-8?B?" . base64_encode('Jürgen Weiß') . "?= <erika@example.org>\r\n", $head);
        self::assertStringContainsString("\r\nSubject: =?UTF-8?B?" . base64_encode('Grüße') . "?=\r\n", $head);
        self::assertStringContainsString("\r\nMessage-ID: " . $messageId . "\r\n", $head);
        self::assertStringContainsString("\r\nAuto-Submitted: auto-generated\r\n", $head . "\r\n");
        self::assertSame("Hallo Jürgen,\r\nbitte prüfen.\r\n", quoted_printable_decode($body));
    }

    public function testFileIsOnlyReadableByOwner(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Unter Windows gibt es keine Unix-Rechte.');
        }

        new FileMailer($this->dir->path)->send(self::message());

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->emlFiles($this->dir->path)[0])), -4));
    }

    public function testEachMailGetsItsOwnFile(): void
    {
        $mailer = new FileMailer($this->dir->path);

        $ids = [$mailer->send(self::message()), $mailer->send(self::message()), $mailer->send(self::message())];

        self::assertCount(3, $this->emlFiles($this->dir->path));
        self::assertCount(3, array_unique($ids));
    }

    public function testMissingDirectoryIsCreated(): void
    {
        $target = $this->dir->file('var') . DIRECTORY_SEPARATOR . 'mail';

        new FileMailer($target)->send(self::message());

        self::assertCount(1, $this->emlFiles($target));
    }

    public function testUnusableDirectoryIsReported(): void
    {
        $this->dir->write('datei', 'kein Ordner');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Ordner für Mails konnte nicht angelegt werden');

        new FileMailer($this->dir->file('datei') . DIRECTORY_SEPARATOR . 'mail')->send(self::message());
    }

    private static function message(): MailMessage
    {
        return new MailMessage(EmailAddress::fromString('erika@example.org'), 'Jürgen Weiß', 'Grüße', "Hallo Jürgen,\nbitte prüfen.");
    }

    /**
     * @return list<string>
     */
    private function emlFiles(string $dir): array
    {
        return glob($dir . DIRECTORY_SEPARATOR . '*.eml') ?: [];
    }
}
