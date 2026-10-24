<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Config;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\DatabaseConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigLoader::class)]
#[CoversClass(ConfigException::class)]
#[UsesClass(DatabaseConfig::class)]
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

    private function load(string $fixture): DatabaseConfig
    {
        return new ConfigLoader()->loadDatabaseConfig(self::FIXTURES . $fixture . '.php');
    }
}
