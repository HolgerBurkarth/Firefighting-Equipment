<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Config;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Config\AppConfig;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Mail\MailConfig;
use FirefightingEquipment\Mail\MailTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigLoader::class)]
#[CoversClass(ConfigException::class)]
#[CoversClass(AppConfig::class)]
#[UsesClass(DatabaseConfig::class)]
#[UsesClass(MailConfig::class)]
final class ConfigLoaderTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/config/';

    /** Gemeinsamer Bestandteil aller Dummy-Werte in den Fixtures. */
    private const string SECRET_MARKER = 'nicht-ausgeben';

    public function testValidFileIsLoaded(): void
    {
        $config = $this->load('valid');

        self::assertSame('host-nicht-ausgeben', $config->host);
        self::assertSame('name-nicht-ausgeben', $config->name);
        self::assertSame('user-nicht-ausgeben', $config->user);
        self::assertSame('pass-nicht-ausgeben', $config->pass);
    }

    public function testEmptyPasswordIsAllowed(): void
    {
        self::assertSame('', $this->load('valid-empty-pass')->pass);
    }

    public function testOutputOfConfigFileIsDiscarded(): void
    {
        $this->expectOutputString('');

        self::assertSame('pass-nicht-ausgeben', $this->load('prints-output')->pass);
    }

    public function testMissingFileIsReported(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('nicht gefunden');

        $this->load('does-not-exist');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'kein Array' => ['not-array', 'muss ein Array zurückgeben'];
        yield 'db fehlt' => ['missing-db', "Gruppe 'db' fehlt"];
        yield 'db kein Array' => ['db-not-array', "'db' muss"];
        yield 'host fehlt' => ['missing-host', "Schlüssel 'db.host' fehlt"];
        yield 'name fehlt' => ['missing-name', "Schlüssel 'db.name' fehlt"];
        yield 'user fehlt' => ['missing-user', "Schlüssel 'db.user' fehlt"];
        yield 'pass fehlt' => ['missing-pass', "Schlüssel 'db.pass' fehlt"];
        yield 'falscher Typ' => ['wrong-type', "'db.host' muss"];
        yield 'leerer Wert' => ['empty-host', "'db.host' darf"];
        yield 'unbekannter Schlüssel' => ['unknown-key', "Unbekannter Schlüssel 'db.password'"];
        yield 'Syntaxfehler' => ['syntax-error', 'enthält einen PHP-Fehler'];
    }

    #[DataProvider('invalidFiles')]
    public function testInvalidFileIsReportedWithoutValues(string $fixture, string $expectedMessage): void
    {
        try {
            $this->load($fixture);
            self::fail('ConfigException erwartet.');
        } catch (ConfigException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());
            self::assertStringNotContainsString(self::SECRET_MARKER, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testAllowedOriginsAreLoaded(): void
    {
        self::assertSame(
            ['http://localhost:8000', 'https://test.example'],
            new ConfigLoader()->loadAllowedOrigins(self::FIXTURES . 'http-valid.php'),
        );
    }

    public function testAllowedOriginsAreEmptyWithoutHttpGroup(): void
    {
        self::assertSame([], new ConfigLoader()->loadAllowedOrigins(self::FIXTURES . 'valid.php'));
        self::assertSame([], new ConfigLoader()->loadAllowedOrigins(self::FIXTURES . 'http-empty.php'));
    }

    public function testDatabaseConfigIgnoresHttpGroup(): void
    {
        self::assertSame('pass-nicht-ausgeben', $this->load('http-valid')->pass);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidHttpFiles(): iterable
    {
        yield 'http kein Array' => ['http-not-array', "'http' muss"];
        yield 'unbekannter Schlüssel' => ['http-unknown-key', "Unbekannter Schlüssel 'http.allowed_origin'"];
        yield 'keine Liste' => ['http-origins-not-list', 'eine Liste sein'];
        yield 'mit Pfad' => ['http-origin-with-path', 'ungültigen Eintrag'];
        yield 'Großbuchstaben' => ['http-origin-uppercase', 'ungültigen Eintrag'];
        yield 'kein Text' => ['http-origin-not-string', 'ungültigen Eintrag'];
    }

    #[DataProvider('invalidHttpFiles')]
    public function testInvalidHttpGroupIsReported(string $fixture, string $expectedMessage): void
    {
        try {
            new ConfigLoader()->loadAllowedOrigins(self::FIXTURES . $fixture . '.php');
            self::fail('ConfigException erwartet.');
        } catch (ConfigException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());
            self::assertStringNotContainsString(self::SECRET_MARKER, $e->getMessage());
        }
    }

    public function testAppConfigIsLoaded(): void
    {
        self::assertSame('http://localhost:8000', new ConfigLoader()->loadAppConfig(self::FIXTURES . 'app-valid.php')->baseUrl);
    }

    public function testAppConfigDefaultsToProduction(): void
    {
        self::assertSame('https://www.prodad.de', new ConfigLoader()->loadAppConfig(self::FIXTURES . 'valid.php')->baseUrl);
        self::assertSame('https://www.prodad.de', new ConfigLoader()->loadAppConfig(self::FIXTURES . 'app-empty.php')->baseUrl);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAppFiles(): iterable
    {
        yield 'app kein Array' => ['app-not-array', "'app' muss"];
        yield 'unbekannter Schlüssel' => ['app-unknown-key', "Unbekannter Schlüssel 'app.url'"];
        yield 'mit Pfad' => ['app-base-url-with-path', "'app.base_url'"];
        yield 'mit / am Ende' => ['app-base-url-trailing-slash', "'app.base_url'"];
        yield 'kein Text' => ['app-base-url-not-string', "'app.base_url'"];
    }

    #[DataProvider('invalidAppFiles')]
    public function testInvalidAppGroupIsReported(string $fixture, string $expectedMessage): void
    {
        try {
            new ConfigLoader()->loadAppConfig(self::FIXTURES . $fixture . '.php');
            self::fail('ConfigException erwartet.');
        } catch (ConfigException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());
            self::assertStringNotContainsString(self::SECRET_MARKER, $e->getMessage());
        }
    }

    public function testMailConfigIsLoaded(): void
    {
        $config = new ConfigLoader()->loadMailConfig(self::FIXTURES . 'mail-file.php');

        self::assertSame(MailTransport::File, $config->transport);
        self::assertSame('tmp/mails', $config->fileDir);
        self::assertSame(MailTransport::Sendmail, new ConfigLoader()->loadMailConfig(self::FIXTURES . 'mail-sendmail.php')->transport);
    }

    public function testMailConfigDefaultsToSendmail(): void
    {
        $config = new ConfigLoader()->loadMailConfig(self::FIXTURES . 'valid.php');

        self::assertSame(MailTransport::Sendmail, $config->transport);
        self::assertSame('var/mail', $config->fileDir);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidMailFiles(): iterable
    {
        yield 'mail kein Array' => ['mail-not-array', "'mail' muss"];
        yield 'unbekannter Schlüssel' => ['mail-unknown-key', "Unbekannter Schlüssel 'mail.dir'"];
        yield 'unbekannter Transport' => ['mail-unknown-transport', "'mail.transport' muss"];
        yield 'Transport großgeschrieben' => ['mail-transport-uppercase', "'mail.transport' muss"];
        yield 'leerer Ordner' => ['mail-empty-file-dir', "'mail.file_dir' muss"];
    }

    #[DataProvider('invalidMailFiles')]
    public function testInvalidMailGroupIsReported(string $fixture, string $expectedMessage): void
    {
        try {
            new ConfigLoader()->loadMailConfig(self::FIXTURES . $fixture . '.php');
            self::fail('ConfigException erwartet.');
        } catch (ConfigException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());
            self::assertStringNotContainsString(self::SECRET_MARKER, $e->getMessage());
        }
    }

    public function testOtherLoadersIgnoreAppAndMailGroups(): void
    {
        self::assertSame('pass-nicht-ausgeben', $this->load('mail-file')->pass);
        self::assertSame([], new ConfigLoader()->loadAllowedOrigins(self::FIXTURES . 'app-valid.php'));
    }

    private function load(string $fixture): DatabaseConfig
    {
        return new ConfigLoader()->loadDatabaseConfig(self::FIXTURES . $fixture . '.php');
    }
}
