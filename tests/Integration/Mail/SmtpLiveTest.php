<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Mail;

use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\Mail\MailTransport;
use FirefightingEquipment\Mail\Smtp\SmtpMailer;
use FirefightingEquipment\Tests\Support\TestDatabase;
use FirefightingEquipment\User\EmailAddress;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Optionaler Test gegen einen echten SMTP-Server. Läuft nur, wenn ausdrücklich eingerichtet:
 * Umgebungsvariable FF_SMTP_LIVE_TO (Empfänger) und in config/config.test.php die Gruppe 'mail'
 * mit 'transport' => 'smtp' und 'smtp' => [...]. Sonst übersprungen; ein normaler Testlauf
 * verschickt also nie eine Mail.
 */
#[CoversNothing]
final class SmtpLiveTest extends TestCase
{
    public function testSendsMailOverConfiguredSmtpServer(): void
    {
        $to = getenv('FF_SMTP_LIVE_TO');
        if ($to === false || $to === '') {
            self::markTestSkipped('FF_SMTP_LIVE_TO nicht gesetzt – SMTP-Live-Test übersprungen.');
        }
        if (!TestDatabase::configExists()) {
            self::markTestSkipped('config/config.test.php fehlt – SMTP-Live-Test übersprungen.');
        }
        $config = new ConfigLoader()->loadMailConfig(TestDatabase::CONFIG_PATH);
        if ($config->transport !== MailTransport::Smtp || $config->smtp === null) {
            self::markTestSkipped("In config/config.test.php ist 'mail.transport' nicht 'smtp' – SMTP-Live-Test übersprungen.");
        }

        $messageId = new SmtpMailer($config->smtp)->send(MailTemplates::testMail(
            EmailAddress::fromString($to),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        self::assertMatchesRegularExpression('/^<[0-9a-f]{32}@prodad\.de>$/D', $messageId);
    }
}
