<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Mail;

use FirefightingEquipment\Mail\FileMailer;
use FirefightingEquipment\Mail\MailConfig;
use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\MailTransport;
use FirefightingEquipment\Mail\SendmailMailer;
use FirefightingEquipment\Mail\Smtp\SmtpConfig;
use FirefightingEquipment\Mail\Smtp\SmtpMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailConfig::class)]
#[UsesClass(FileMailer::class)]
#[UsesClass(MailException::class)]
#[UsesClass(SendmailMailer::class)]
#[UsesClass(SmtpConfig::class)]
#[UsesClass(SmtpMailer::class)]
final class MailConfigTest extends TestCase
{
    public function testDefaultIsSendmail(): void
    {
        self::assertInstanceOf(SendmailMailer::class, new MailConfig()->createMailer('/projekt'));
    }

    public function testFileTransportUsesDirectoryInProject(): void
    {
        $mailer = new MailConfig(MailTransport::File)->createMailer('/apps/firefighting-equipment/');

        self::assertInstanceOf(FileMailer::class, $mailer);
        self::assertSame('/apps/firefighting-equipment' . DIRECTORY_SEPARATOR . 'var/mail', $mailer->directory);
    }

    public function testSmtpTransportUsesSmtpGroup(): void
    {
        $smtp = new SmtpConfig('feuerwehr-app@prodad.de', 'geheim');

        $mailer = new MailConfig(MailTransport::Smtp, smtp: $smtp)->createMailer('/projekt');

        self::assertInstanceOf(SmtpMailer::class, $mailer);
        self::assertSame($smtp, $mailer->config);
    }

    public function testSmtpTransportWithoutGroupFails(): void
    {
        $this->expectException(MailException::class);

        new MailConfig(MailTransport::Smtp)->createMailer('/projekt');
    }

    public function testSmtpGroupIsIgnoredByOtherTransports(): void
    {
        $config = new MailConfig(MailTransport::File, 'mails', new SmtpConfig('feuerwehr-app@prodad.de', 'geheim'));

        self::assertInstanceOf(FileMailer::class, $config->createMailer('/projekt'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function absoluteDirectories(): iterable
    {
        yield 'Unix' => ['/tmp/mails'];
        yield 'Windows' => ['C:\\Temp\\mails'];
        yield 'Windows mit /' => ['D:/mails'];
        yield 'Netzwerkpfad' => ['\\\\server\\mails'];
    }

    #[DataProvider('absoluteDirectories')]
    public function testAbsoluteDirectoryIsKept(string $dir): void
    {
        self::assertSame($dir, new MailConfig(MailTransport::File, $dir)->fileDirectory('/projekt'));
    }
}
