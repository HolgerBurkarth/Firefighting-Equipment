<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Mail;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Mail\ConfiguredMailer;
use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MimeMessage;
use FirefightingEquipment\Mail\Notifier;
use FirefightingEquipment\Tests\Support\FakeMailer;
use FirefightingEquipment\User\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Notifier::class)]
#[CoversClass(ConfiguredMailer::class)]
#[UsesClass(ConfigException::class)]
#[UsesClass(DatabaseError::class)]
#[UsesClass(ErrorReporter::class)]
#[UsesClass(MailException::class)]
#[UsesClass(MailMessage::class)]
#[UsesClass(MimeMessage::class)]
#[UsesClass(EmailAddress::class)]
final class NotifierTest extends TestCase
{
    private const string SECRET_LINK = 'https://www.prodad.de/firefighting/x/passwort-zuruecksetzen#token=GEHEIM';

    /** @var list<string> */
    private array $log = [];

    public function testSendsMail(): void
    {
        $mailer = new FakeMailer();

        self::assertTrue($this->notifier($mailer)->send('password_reset', 7, self::message(...)));

        self::assertSame(self::SECRET_LINK, $mailer->only()->text);
        self::assertSame([], $this->log);
    }

    public function testFailureIsLoggedWithoutRecipientTextOrToken(): void
    {
        self::assertFalse($this->notifier(new FakeMailer(fail: true))->send('password_reset', 7, self::message(...)));

        self::assertCount(1, $this->log);
        self::assertStringStartsWith('firefighting: mail_failed (password_reset, Konto #7): ', $this->log[0]);
        self::assertStringNotContainsString('GEHEIM', $this->log[0]);
        self::assertStringNotContainsString('erika@example.org', $this->log[0]);
    }

    public function testErrorWhileBuildingTheMailIsCaught(): void
    {
        $sent = $this->notifier(new FakeMailer())->send('password_changed', null, static function (): MailMessage {
            throw new \RuntimeException('Konfiguration kaputt');
        });

        self::assertFalse($sent);
        self::assertStringStartsWith('firefighting: mail_failed (password_changed): RuntimeException: Konfiguration kaputt', $this->log[0]);
    }

    public function testConfiguredMailerLoadsConfigurationOnlyWhenSending(): void
    {
        $loads = 0;
        $inner = new FakeMailer();
        $mailer = new ConfiguredMailer(static function () use (&$loads, $inner): FakeMailer {
            $loads++;

            return $inner;
        });
        self::assertSame(0, $loads);

        $mailer->send(self::message());
        $mailer->send(self::message());

        self::assertSame(1, $loads);
        self::assertCount(2, $inner->sent);
    }

    public function testUnusableConfigurationBecomesMailException(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Mail-Konfiguration nicht nutzbar.');

        ConfiguredMailer::fromConfigFile('gibt-es-nicht.php', __DIR__)->send(self::message());
    }

    private function notifier(FakeMailer $mailer): Notifier
    {
        return new Notifier($mailer, new ErrorReporter(function (string $line): void {
            $this->log[] = $line;
        }));
    }

    private static function message(): MailMessage
    {
        return new MailMessage(EmailAddress::fromString('erika@example.org'), null, 'Betreff', self::SECRET_LINK);
    }
}
