<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Mail\Smtp;

use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MimeMessage;
use FirefightingEquipment\Mail\Notifier;
use FirefightingEquipment\Mail\Smtp\SmtpConfig;
use FirefightingEquipment\Mail\Smtp\SmtpConnection;
use FirefightingEquipment\Mail\Smtp\SmtpEncryption;
use FirefightingEquipment\Mail\Smtp\SmtpMailer;
use FirefightingEquipment\Mail\Smtp\SmtpReply;
use FirefightingEquipment\Tests\Support\FakeSmtpServer;
use FirefightingEquipment\Tests\Support\TempDirectory;
use FirefightingEquipment\Tests\Support\TestCertificates;
use FirefightingEquipment\User\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * SMTP-Versand gegen einen Fake-Server (eigener Prozess auf 127.0.0.1) mit Test-CA. Kein echter
 * Mailserver; das Zertifikat wird wie in Produktion geprüft, nur die vertrauenswürdige Stelle ist
 * die Test-CA.
 */
#[CoversClass(SmtpMailer::class)]
#[CoversClass(SmtpConnection::class)]
#[CoversClass(SmtpReply::class)]
#[CoversClass(SmtpConfig::class)]
#[UsesClass(DatabaseError::class)]
#[UsesClass(EmailAddress::class)]
#[UsesClass(ErrorReporter::class)]
#[UsesClass(MailException::class)]
#[UsesClass(MailMessage::class)]
#[UsesClass(MimeMessage::class)]
#[UsesClass(Notifier::class)]
final class SmtpMailerTest extends TestCase
{
    private const string PASSWORD = 'Geheim-Pa55wort-nicht-ausgeben';
    private const string RECIPIENT = 'erika@example.org';

    private static TempDirectory $dir;
    private static TestCertificates $certificates;
    private static string $serverCert;

    /** @var list<FakeSmtpServer> */
    private array $servers = [];

    public static function setUpBeforeClass(): void
    {
        self::$dir = new TempDirectory();
        self::$certificates = new TestCertificates(self::$dir);
        self::$serverCert = self::$certificates->server('IP:127.0.0.1');
    }

    public static function tearDownAfterClass(): void
    {
        self::$dir->remove();
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->remove();
        }
    }

    public function testSendsMailOverImplicitTlsWithAuthPlain(): void
    {
        $server = $this->server([
            ['tls' => true],
            ['send' => '220 fake.example ESMTP'],
            ['read' => true],
            ['send' => "250-fake.example\n250-SIZE 10000000\n250 AUTH PLAIN LOGIN"],
            ['read' => true],
            ['send' => '235 2.7.0 Authentication successful'],
            ...self::envelopeAndData(),
        ]);

        $messageId = $this->mailer($server)->send(self::message("Hallo,\n.Punkt am Anfang\nGrüße"));

        $received = $server->received();
        self::assertSame([
            '<tls>',
            'EHLO prodad.de',
            'AUTH PLAIN ' . base64_encode("\0feuerwehr-app@prodad.de\0" . self::PASSWORD),
            'MAIL FROM:<feuerwehr-app@prodad.de>',
            'RCPT TO:<erika@example.org>',
            'DATA',
        ], array_slice($received, 0, 6));
        self::assertSame('QUIT', $received[7]);

        $data = $received[6];
        self::assertStringStartsWith("To: \"Erika Musterfrau\" <erika@example.org>\r\nSubject: ", $data);
        self::assertStringContainsString("\r\nMessage-ID: " . $messageId . "\r\n", $data);
        self::assertStringContainsString("\r\nFrom: =?UTF-8?B?" . base64_encode(MimeMessage::FROM_NAME) . "?=\r\n <feuerwehr-app@prodad.de>\r\n", $data);
        self::assertStringContainsString("\r\nAuto-Submitted: auto-generated\r\n", $data);
        self::assertStringContainsString("\r\nContent-Transfer-Encoding: quoted-printable\r\n", $data);
        self::assertStringEndsWith("\r\n", $data);
        $body = substr($data, (int) strpos($data, "\r\n\r\n") + 4);
        self::assertStringContainsString("\r\n..Punkt am Anfang\r\n", $body, 'Dot-Stuffing');
        self::assertSame("Hallo,\r\n.Punkt am Anfang\r\nGrüße\r\n", quoted_printable_decode(str_replace("\r\n..", "\r\n.", $body)));
    }

    public function testSendsMailOverStartTlsWithAuthLogin(): void
    {
        $server = $this->server([
            ['send' => '220 fake.example ESMTP'],
            ['read' => true],
            ['send' => "250-fake.example\n250 STARTTLS"],
            ['read' => true],
            ['send' => '220 2.0.0 Ready to start TLS'],
            ['tls' => true],
            ['read' => true],
            ['send' => "250-fake.example\n250 AUTH=LOGIN"],
            ['read' => true],
            ['send' => '334 VXNlcm5hbWU6'],
            ['read' => true],
            ['send' => '334 UGFzc3dvcmQ6'],
            ['read' => true],
            ['send' => '235 2.7.0 Authentication successful'],
            ...self::envelopeAndData(),
        ]);

        $this->mailer($server, SmtpEncryption::StartTls)->send(self::message());

        self::assertSame([
            'EHLO prodad.de',
            'STARTTLS',
            '<tls>',
            'EHLO prodad.de',
            'AUTH LOGIN',
            base64_encode('feuerwehr-app@prodad.de'),
            base64_encode(self::PASSWORD),
            'MAIL FROM:<feuerwehr-app@prodad.de>',
            'RCPT TO:<erika@example.org>',
            'DATA',
        ], array_slice($server->received(), 0, 10));
    }

    public function testMissingStartTlsAbortsBeforeAuthentication(): void
    {
        $server = $this->server([
            ['send' => '220 fake.example ESMTP'],
            ['read' => true],
            ['send' => "250-fake.example\n250 AUTH PLAIN LOGIN"],
            ['read' => true],
        ]);

        $e = $this->sendExpectingFailure($this->mailer($server, SmtpEncryption::StartTls));

        self::assertSame('Der Server 127.0.0.1:' . $server->port . ' (starttls) bietet kein STARTTLS an; Versand abgebrochen.', $e->getMessage());
        self::assertSame(['EHLO prodad.de', '<geschlossen>'], $server->received());
    }

    public function testServerWithoutPlainOrLoginIsRejected(): void
    {
        $server = $this->server([
            ['tls' => true],
            ['send' => '220 fake.example ESMTP'],
            ['read' => true],
            ['send' => "250-fake.example\n250 AUTH CRAM-MD5 XOAUTH2"],
            ['read' => true],
        ]);

        $e = $this->sendExpectingFailure($this->mailer($server));

        self::assertStringContainsString('bietet keine Anmeldung mit PLAIN oder LOGIN an', $e->getMessage());
        self::assertSame(['<tls>', 'EHLO prodad.de', '<geschlossen>'], $server->received());
    }

    public function testFailedAuthenticationNeverRevealsPassword(): void
    {
        $server = $this->server([
            ['tls' => true],
            ['send' => '220 fake.example ESMTP'],
            ['read' => true],
            ['send' => "250-fake.example\n250 AUTH PLAIN"],
            ['read' => true],
            ['send' => '535 5.7.8 Authentication credentials invalid'],
        ]);

        $e = $this->sendExpectingFailure($this->mailer($server));

        self::assertSame('SMTP-Fehler bei AUTH: 535 5.7.8 Authentication credentials invalid', $e->getMessage());
        self::assertSecretsAbsent(self::describeThrowable($e));
    }

    public function testRejectedRecipientIsMaskedInMessage(): void
    {
        $server = $this->server([
            ['tls' => true],
            ['send' => '220 fake.example ESMTP'],
            ['read' => true],
            ['send' => "250-fake.example\n250 AUTH PLAIN"],
            ['read' => true],
            ['send' => '235 ok'],
            ['read' => true],
            ['send' => '250 ok'],
            ['read' => true],
            ['send' => '550 5.1.1 <erika@example.org>: Recipient address rejected: user@other.example unknown'],
        ]);

        $e = $this->sendExpectingFailure($this->mailer($server));

        self::assertSame('SMTP-Fehler bei RCPT TO: 550 5.1.1 <<Adresse>>: Recipient address rejected: <Adresse> unknown', $e->getMessage());
    }

    public function testNotifierLogsNeitherPasswordNorRecipient(): void
    {
        $server = $this->server([
            ['tls' => true],
            ['send' => '220 fake.example ESMTP'],
            ['read' => true],
            ['send' => "250-fake.example\n250 AUTH LOGIN"],
            ['read' => true],
            ['send' => '334 VXNlcm5hbWU6'],
            ['read' => true],
            ['send' => '334 UGFzc3dvcmQ6'],
            ['read' => true],
            ['send' => '535 5.7.8 Authentication failed for feuerwehr-app@prodad.de'],
        ]);
        $log = [];
        $notifier = new Notifier($this->mailer($server), new ErrorReporter(static function (string $line) use (&$log): void {
            $log[] = $line;
        }));

        self::assertFalse($notifier->send('password_reset', 42, static fn (): MailMessage => self::message()));

        $logged = implode("\n", $log);
        self::assertStringContainsString('mail_failed (password_reset, Konto #42)', $logged);
        self::assertStringContainsString('SMTP-Fehler bei AUTH: 535', $logged);
        self::assertSecretsAbsent($logged);
        self::assertStringNotContainsString(self::RECIPIENT, $logged);
        self::assertStringNotContainsString('feuerwehr-app@prodad.de', $logged);
    }

    public function testCertificateFromUnknownAuthorityIsRejected(): void
    {
        $server = $this->server([['tls' => true], ['send' => '220 fake.example ESMTP']]);

        // Ohne Test-CA: nur die Stellen des Systems gelten, die Test-CA gehört nicht dazu.
        $e = $this->sendExpectingFailure(new SmtpMailer(self::config($server)));

        self::assertStringStartsWith('TLS-Verbindung zu 127.0.0.1:' . $server->port . ' fehlgeschlagen', $e->getMessage());
        self::assertNotContains('EHLO prodad.de', $server->received());
    }

    public function testCertificateFromOtherAuthorityIsRejected(): void
    {
        $other = new TestCertificates(self::$dir, 'Andere Test-CA');
        $server = $this->server([['tls' => true], ['send' => '220 fake.example ESMTP']]);

        $e = $this->sendExpectingFailure(new SmtpMailer(self::config($server), $other->caFile));

        self::assertStringStartsWith('TLS-Verbindung zu 127.0.0.1:' . $server->port . ' fehlgeschlagen', $e->getMessage());
        self::assertNotContains('EHLO prodad.de', $server->received());
    }

    public function testCertificateForOtherHostIsRejected(): void
    {
        $server = $this->server([['tls' => true], ['send' => '220 fake.example ESMTP']], self::$certificates->server('DNS:mail.example.org'));

        $e = $this->sendExpectingFailure($this->mailer($server));

        self::assertStringStartsWith('TLS-Verbindung zu 127.0.0.1:' . $server->port . ' fehlgeschlagen', $e->getMessage());
        self::assertNotContains('EHLO prodad.de', $server->received());
    }

    public function testStartTlsAlsoVerifiesCertificate(): void
    {
        $server = $this->server([
            ['send' => '220 fake.example ESMTP'],
            ['read' => true],
            ['send' => "250-fake.example\n250 STARTTLS"],
            ['read' => true],
            ['send' => '220 Ready'],
            ['tls' => true],
            ['read' => true],
        ], self::$certificates->server('DNS:mail.example.org'));

        $e = $this->sendExpectingFailure($this->mailer($server, SmtpEncryption::StartTls));

        self::assertStringStartsWith('TLS-Verbindung zu 127.0.0.1:' . $server->port . ' fehlgeschlagen', $e->getMessage());
        self::assertNotContains('EHLO prodad.de', array_slice($server->received(), 2));
    }

    public function testSlowServerRunsIntoTimeout(): void
    {
        $server = $this->server([['tls' => true], ['sleep' => 5]]);
        $started = microtime(true);

        $e = $this->sendExpectingFailure($this->mailer($server, timeout: 0.5));

        self::assertSame('Zeitüberschreitung bei 127.0.0.1:' . $server->port . ' (Schritt Begrüßung, Zeitlimit 0.5 s).', $e->getMessage());
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testServerClosingConnectionIsReported(): void
    {
        $server = $this->server([['tls' => true], ['send' => '220 fake.example ESMTP'], ['read' => true], ['close' => true]]);

        $e = $this->sendExpectingFailure($this->mailer($server));

        self::assertSame('Verbindung zu 127.0.0.1:' . $server->port . ' vom Server beendet (Schritt EHLO).', $e->getMessage());
    }

    public function testInvalidReplyIsReported(): void
    {
        $server = $this->server([['tls' => true], ['send' => 'HTTP/1.1 400 Bad Request']]);

        $e = $this->sendExpectingFailure($this->mailer($server));

        self::assertSame('Ungültige Antwort von 127.0.0.1:' . $server->port . ' (Schritt Begrüßung): HTTP/1.1 400 Bad Request', $e->getMessage());
    }

    public function testUnreachableServerIsReported(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0') ?: throw new \RuntimeException('Kein freier Port.');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $config = new SmtpConfig(MimeMessage::FROM_ADDRESS, self::PASSWORD, '127.0.0.1', (int) substr($name, strrpos($name, ':') + 1), timeoutSeconds: 1.0);

        try {
            new SmtpMailer($config, self::$certificates->caFile)->send(self::message());
            self::fail('MailException erwartet.');
        } catch (MailException $e) {
            self::assertStringStartsWith('Verbindung zu ' . $name . ' fehlgeschlagen: ', $e->getMessage());
            self::assertSecretsAbsent(self::describeThrowable($e));
        }
    }

    public function testDebugOutputHidesPassword(): void
    {
        $mailer = new SmtpMailer(new SmtpConfig(MimeMessage::FROM_ADDRESS, self::PASSWORD));

        ob_start();
        var_dump($mailer);
        $dump = (string) ob_get_clean();

        self::assertStringContainsString('smtp.strato.de', $dump);
        self::assertStringNotContainsString(self::PASSWORD, $dump);
        self::assertStringNotContainsString(self::PASSWORD, print_r($mailer, true));
        self::assertStringNotContainsString(self::PASSWORD, print_r($mailer->config, true));
    }

    public function testDotStuffing(): void
    {
        self::assertSame("..a\r\nb\r\n..\r\n...c\r\n", SmtpMailer::dotStuff(".a\r\nb\r\n.\r\n..c"));
        self::assertSame("a.b\r\n", SmtpMailer::dotStuff("a.b\r\n"));
    }

    /**
     * Schritte nach erfolgreicher Anmeldung: MAIL FROM, RCPT TO, DATA, Text, QUIT.
     *
     * @return list<array<string, mixed>>
     */
    private static function envelopeAndData(): array
    {
        return [
            ['read' => true],
            ['send' => '250 2.1.0 Ok'],
            ['read' => true],
            ['send' => '250 2.1.5 Ok'],
            ['read' => true],
            ['send' => '354 End data with <CR><LF>.<CR><LF>'],
            ['data' => true],
            ['send' => '250 2.0.0 Ok: queued'],
            ['read' => true],
            ['send' => '221 2.0.0 Bye'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function server(array $steps, ?string $certFile = null): FakeSmtpServer
    {
        $server = FakeSmtpServer::start($steps, $certFile ?? self::$serverCert);
        $this->servers[] = $server;

        return $server;
    }

    private function mailer(FakeSmtpServer $server, SmtpEncryption $encryption = SmtpEncryption::Ssl, float $timeout = 5.0): SmtpMailer
    {
        return new SmtpMailer(self::config($server, $encryption, $timeout), self::$certificates->caFile);
    }

    private static function config(FakeSmtpServer $server, SmtpEncryption $encryption = SmtpEncryption::Ssl, float $timeout = 5.0): SmtpConfig
    {
        return new SmtpConfig(MimeMessage::FROM_ADDRESS, self::PASSWORD, '127.0.0.1', $server->port, $encryption, $timeout);
    }

    private function sendExpectingFailure(SmtpMailer $mailer): MailException
    {
        try {
            $mailer->send(self::message());
        } catch (MailException $e) {
            self::assertSecretsAbsent(self::describeThrowable($e));

            return $e;
        }
        self::fail('MailException erwartet.');
    }

    private static function message(string $text = 'Hallo'): MailMessage
    {
        return new MailMessage(EmailAddress::fromString(self::RECIPIENT), 'Erika Musterfrau', 'Testmail äöü', $text);
    }

    /**
     * Alles, was über eine Ausnahme ins Protokoll oder auf den Bildschirm gelangen könnte: Meldungen
     * und Traces der ganzen Kette, auch die Argumente der Aufrufe.
     */
    private static function describeThrowable(\Throwable $e): string
    {
        $text = '';
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $text .= $current->getMessage() . "\n" . $current->getTraceAsString() . "\n" . print_r($current->getTrace(), true) . "\n" . $current;
        }

        return $text;
    }

    private static function assertSecretsAbsent(string $text): void
    {
        self::assertStringNotContainsString(self::PASSWORD, $text);
        self::assertStringNotContainsString(base64_encode(self::PASSWORD), $text);
        self::assertStringNotContainsString(base64_encode("\0feuerwehr-app@prodad.de\0" . self::PASSWORD), $text);
    }
}
