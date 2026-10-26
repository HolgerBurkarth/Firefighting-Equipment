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

    public function testSendManySendsAllInOneBatch(): void
    {
        $mailer = new FakeMailer();

        $sent = $this->notifier($mailer)->sendMany('device_log', [[1, self::message(...)], [2, self::message(...)], [3, self::message(...)]]);

        self::assertSame(3, $sent);
        self::assertCount(3, $mailer->sent);
        self::assertSame([3], $mailer->batches, 'ein Aufruf des Transports');
        self::assertSame([], $this->log);
    }

    public function testSendManyLogsEachFailureWithoutRecipientOrText(): void
    {
        $sent = $this->notifier(new FakeMailer(fail: true))->sendMany('device_log', [
            [1, self::message(...)],
            [2, static fn (): MailMessage => throw new \RuntimeException('kaputt')],
            [3, self::message(...)],
        ]);

        self::assertSame(0, $sent);
        self::assertCount(3, $this->log);
        self::assertStringStartsWith('firefighting: mail_failed (device_log, Konto #2): RuntimeException: kaputt', $this->log[0]);
        self::assertStringStartsWith('firefighting: mail_failed (device_log, Konto #1): ', $this->log[1]);
        self::assertStringStartsWith('firefighting: mail_failed (device_log, Konto #3): ', $this->log[2]);
        foreach ($this->log as $line) {
            self::assertStringNotContainsString('GEHEIM', $line);
            self::assertStringNotContainsString('erika@example.org', $line);
        }
    }

    public function testSendManyStopsAtDeadline(): void
    {
        $mailer = new FakeMailer();

        $sent = $this->notifier($mailer)->sendMany('device_log', [[1, self::message(...)], [2, self::message(...)]], microtime(true) - 1.0);

        self::assertSame(0, $sent);
        self::assertSame([], $mailer->sent);
        self::assertCount(2, $this->log);
        self::assertStringContainsString('Zeitbudget für den Versand überschritten', $this->log[0]);
    }

    public function testConfiguredMailerSendManyWithUnusableConfiguration(): void
    {
        $results = ConfiguredMailer::fromConfigFile('gibt-es-nicht.php', __DIR__)->sendMany([self::message(), self::message()]);

        self::assertCount(2, $results);
        self::assertContainsOnlyInstancesOf(MailException::class, $results);
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
