<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Cli;

use FirefightingEquipment\Cli\MailTestCommand;
use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Mail\FileMailer;
use FirefightingEquipment\Mail\MailConfig;
use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\Mail\MimeMessage;
use FirefightingEquipment\Mail\Smtp\SmtpConfig;
use FirefightingEquipment\Mail\Smtp\SmtpConnection;
use FirefightingEquipment\Mail\Smtp\SmtpEncryption;
use FirefightingEquipment\Mail\Smtp\SmtpMailer;
use FirefightingEquipment\Mail\Smtp\SmtpReply;
use FirefightingEquipment\Tests\Support\FakeMailer;
use FirefightingEquipment\Tests\Support\FakeSmtpServer;
use FirefightingEquipment\Tests\Support\TempDirectory;
use FirefightingEquipment\Tests\Support\TestCertificates;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\InvalidUserValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailTestCommand::class)]
#[UsesClass(ConfigException::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(EmailAddress::class)]
#[UsesClass(FileMailer::class)]
#[UsesClass(InvalidUserValueException::class)]
#[UsesClass(MailConfig::class)]
#[UsesClass(MailException::class)]
#[UsesClass(MailMessage::class)]
#[UsesClass(MailTemplates::class)]
#[UsesClass(MimeMessage::class)]
#[UsesClass(SmtpConfig::class)]
#[UsesClass(SmtpConnection::class)]
#[UsesClass(SmtpMailer::class)]
#[UsesClass(SmtpReply::class)]
final class MailTestCommandTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/config/';

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        $this->out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
        $this->err = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
    }

    protected function tearDown(): void
    {
        fclose($this->out);
        fclose($this->err);
    }

    public function testSendsTestMailOverConfiguredTransport(): void
    {
        $mailer = new FakeMailer();
        $configs = [];
        $command = new MailTestCommand('gibt-es-nicht.php', '/projekt', $this->out, $this->err, static function (MailConfig $config) use ($mailer, &$configs): FakeMailer {
            $configs[] = $config;

            return $mailer;
        });

        $exitCode = $command->run(['--to=Test@Example.org', '--config=' . self::FIXTURES . 'valid.php']);

        self::assertSame(MailTestCommand::EXIT_OK, $exitCode, $this->err());
        self::assertSame('test@example.org', $mailer->only()->to->value);
        self::assertSame('sendmail', $configs[0]->transport->value);
        self::assertMatchesRegularExpression('/^Testmail an test@example\.org übergeben \(Transport: sendmail, Dauer: \d+\.\d\d s\)\.\r?$/m', $this->out());
        self::assertMatchesRegularExpression('/^Message-ID: <[0-9a-f]{32}@prodad\.de>\r?$/m', $this->out());
        self::assertStringContainsString('SPF, DKIM', $this->out());
    }

    public function testFileTransportWritesEmlFile(): void
    {
        $dir = new TempDirectory();
        try {
            $dir->write('config.php', "<?php\nreturn ['db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'pass' => ''],"
                . " 'mail' => ['transport' => 'file', 'file_dir' => 'mails']];\n");

            $exitCode = new MailTestCommand('gibt-es-nicht.php', $dir->path, $this->out, $this->err)
                ->run(['--to=test@example.org', '--config=' . $dir->file('config.php')]);

            self::assertSame(MailTestCommand::EXIT_OK, $exitCode, $this->err());
            self::assertCount(1, glob($dir->file('mails') . DIRECTORY_SEPARATOR . '*.eml') ?: []);
            self::assertStringContainsString('Abgelegt in: ' . $dir->file('mails'), $this->out());
        } finally {
            $dir->remove();
        }
    }

    public function testFailedSendGivesExitCode1(): void
    {
        $command = new MailTestCommand('gibt-es-nicht.php', '/projekt', $this->out, $this->err, static fn (): FakeMailer => new FakeMailer(fail: true));

        self::assertSame(MailTestCommand::EXIT_ERROR, $command->run(['--to=test@example.org', '--config=' . self::FIXTURES . 'valid.php']));
        self::assertStringContainsString('FEHLER: Versand absichtlich fehlgeschlagen', $this->err());
        self::assertMatchesRegularExpression('/^\(Transport: sendmail, Dauer: \d+\.\d\d s\)\r?$/m', $this->err());
    }

    public function testSmtpShowsServerButNoCredentials(): void
    {
        $command = new MailTestCommand('gibt-es-nicht.php', '/projekt', $this->out, $this->err, static fn (): FakeMailer => new FakeMailer());

        $exitCode = $command->run(['--to=test@example.org', '--config=' . self::FIXTURES . 'mail-smtp.php']);

        self::assertSame(MailTestCommand::EXIT_OK, $exitCode, $this->err());
        self::assertMatchesRegularExpression(
            '/^Testmail an test@example\.org übergeben \(Transport: smtp, Server: mail\.example\.org:587 \(starttls\), Dauer: \d+\.\d\d s\)\.\r?$/m',
            $this->out(),
        );
        self::assertStringContainsString('SPF, DKIM, DMARC', $this->out());
        self::assertStringNotContainsString('nicht-ausgeben', $this->out() . $this->err());
    }

    public function testSmtpErrorShowsServerReplyWithoutCredentials(): void
    {
        $dir = new TempDirectory();
        $certificates = new TestCertificates($dir);
        $server = FakeSmtpServer::start([
            ['tls' => true],
            ['send' => '220 fake.example ESMTP'],
            ['read' => true],
            ['send' => "250-fake.example\n250 AUTH PLAIN"],
            ['read' => true],
            ['send' => '535 5.7.8 Authentication credentials invalid'],
        ], $certificates->server('IP:127.0.0.1'));
        try {
            $command = new MailTestCommand('gibt-es-nicht.php', '/projekt', $this->out, $this->err, static function (MailConfig $config) use ($server, $certificates): SmtpMailer {
                self::assertNotNull($config->smtp);

                // Wie konfiguriert, nur mit dem Fake-Server als Ziel und der Test-CA.
                return new SmtpMailer(new SmtpConfig(
                    $config->smtp->username,
                    $config->smtp->password,
                    '127.0.0.1',
                    $server->port,
                    SmtpEncryption::Ssl,
                    $config->smtp->timeoutSeconds,
                ), $certificates->caFile);
            });

            $exitCode = $command->run(['--to=test@example.org', '--config=' . self::FIXTURES . 'mail-smtp.php']);
        } finally {
            $server->remove();
            $dir->remove();
        }

        self::assertSame(MailTestCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('FEHLER: SMTP-Fehler bei AUTH: 535 5.7.8 Authentication credentials invalid', $this->err());
        self::assertMatchesRegularExpression('/^\(Transport: smtp, Server: mail\.example\.org:587 \(starttls\), Dauer: \d+\.\d\d s\)\r?$/m', $this->err());
        self::assertStringNotContainsString('nicht-ausgeben', $this->out() . $this->err());
        self::assertStringNotContainsString(base64_encode("\0feuerwehr-app@prodad.de\0smtp-pass-nicht-ausgeben"), $this->out() . $this->err());
    }

    public function testInvalidAddressOrConfigGivesExitCode1(): void
    {
        $command = new MailTestCommand('gibt-es-nicht.php', '/projekt', $this->out, $this->err, static fn (): FakeMailer => new FakeMailer());

        self::assertSame(MailTestCommand::EXIT_ERROR, $command->run(['--to=keine-adresse']));
        self::assertStringContainsString('Ungültige E-Mail-Adresse', $this->err());
        self::assertSame(MailTestCommand::EXIT_ERROR, $command->run(['--to=test@example.org']));
        self::assertStringContainsString('Konfigurationsdatei nicht gefunden', $this->err());
        self::assertSame(MailTestCommand::EXIT_ERROR, $command->run(['--to=test@example.org', '--config=' . self::FIXTURES . 'mail-unknown-transport.php']));
        self::assertStringContainsString("'mail.transport' muss", $this->err());
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidCalls(): iterable
    {
        yield 'ohne Empfänger' => [[], 'Empfänger fehlt'];
        yield 'leerer Empfänger' => [['--to='], '--to= braucht einen Wert'];
        yield 'doppelt' => [['--to=a@example.org', '--to=b@example.org'], '--to ist doppelt'];
        yield 'unbekannte Option' => [['--to=a@example.org', '--cc=geheim@example.org'], 'Unbekannte Option: --cc'];
        yield 'weiteres Argument' => [['--to=a@example.org', 'los'], 'Unerwartetes Argument: los'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidCalls')]
    public function testInvalidCallGivesExitCode2(array $args, string $message): void
    {
        self::assertSame(MailTestCommand::EXIT_USAGE, new MailTestCommand('gibt-es-nicht.php', '/projekt', $this->out, $this->err)->run($args));
        self::assertStringContainsString('FEHLER: ' . $message, $this->err());
        self::assertStringNotContainsString('geheim', $this->err());
    }

    public function testHelp(): void
    {
        self::assertSame(MailTestCommand::EXIT_OK, new MailTestCommand('gibt-es-nicht.php', '/projekt', $this->out, $this->err)->run(['--help']));
        self::assertStringContainsString('php bin/mail-test.php --to=ADRESSE', $this->out());
    }

    private function out(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }

    private function err(): string
    {
        rewind($this->err);

        return (string) stream_get_contents($this->err);
    }
}
