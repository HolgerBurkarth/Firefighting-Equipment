<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Cli;

use FirefightingEquipment\Cli\TenantCommand;
use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Tenant\InvalidTenantValueException;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantSlug;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantCommand::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConfigException::class)]
#[UsesClass(InvalidTenantValueException::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantSlug::class)]
final class TenantCommandTest extends TestCase
{
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

    public function testParseList(): void
    {
        self::assertSame(
            ['command' => 'list', 'slug' => null, 'name' => null, 'config' => null],
            TenantCommand::parseArguments(['list']),
        );
    }

    public function testParseCreateWithConfig(): void
    {
        self::assertSame(
            ['command' => 'create', 'slug' => 'immendingen', 'name' => 'Feuerwehr Immendingen', 'config' => 'pfad/zur config.php'],
            TenantCommand::parseArguments([
                '--name=Feuerwehr Immendingen',
                'create',
                '--config=pfad/zur config.php',
                '--slug=immendingen',
            ]),
        );
    }

    public function testParseDeactivateAndActivate(): void
    {
        self::assertSame('deactivate', TenantCommand::parseArguments(['deactivate', '--slug=a'])['command']);
        self::assertSame('activate', TenantCommand::parseArguments(['activate', '--slug=a'])['command']);
    }

    public function testParseKeepsEqualsSignInValue(): void
    {
        self::assertSame('A = B', TenantCommand::parseArguments(['create', '--slug=a', '--name=A = B'])['name']);
    }

    public function testParseHelp(): void
    {
        self::assertSame('help', TenantCommand::parseArguments(['--help'])['command']);
        self::assertSame('help', TenantCommand::parseArguments(['-h'])['command']);
        self::assertSame('help', TenantCommand::parseArguments(['help'])['command']);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'kein Befehl' => [[], 'Kein Befehl angegeben.'];
        yield 'unbekannter Befehl' => [['delete', '--slug=a'], 'Unbekannter Befehl: delete'];
        yield 'umbenennen gibt es nicht' => [['rename', '--slug=a'], 'Unbekannter Befehl: rename'];
        yield 'zwei Befehle' => [['list', 'list'], 'Unerwartetes Argument: list'];
        yield 'unbekannte Option' => [['list', '--force'], 'Unbekannte Option: --force'];
        yield 'Option ohne =' => [['deactivate', '--slug', 'a'], 'Unbekannte Option: --slug'];
        yield 'create ohne slug' => [['create', '--name=X'], 'create braucht --slug=KÜRZEL.'];
        yield 'create ohne name' => [['create', '--slug=a'], 'create braucht --name="NAME".'];
        yield 'deactivate ohne slug' => [['deactivate'], 'deactivate braucht --slug=KÜRZEL.'];
        yield 'activate ohne slug' => [['activate'], 'activate braucht --slug=KÜRZEL.'];
        yield 'activate mit name' => [['activate', '--slug=a', '--name=X'], '--name ist bei activate nicht möglich.'];
        yield 'list mit slug' => [['list', '--slug=a'], '--slug ist bei list nicht möglich.'];
        yield 'slug doppelt' => [['deactivate', '--slug=a', '--slug=b'], '--slug ist doppelt angegeben.'];
        yield 'slug leer' => [['deactivate', '--slug='], '--slug= braucht einen Wert.'];
        yield 'config leer' => [['list', '--config='], '--config= braucht einen Pfad.'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsGiveExitCode2(array $args, string $message): void
    {
        $exitCode = $this->command()->run($args);

        self::assertSame(TenantCommand::EXIT_USAGE, $exitCode);
        self::assertSame('', $this->contents($this->out));
        $err = $this->contents($this->err);
        self::assertStringStartsWith('FEHLER: ' . $message, $err);
        self::assertStringContainsString('Aufruf:', $err);
    }

    public function testHelpPrintsUsage(): void
    {
        self::assertSame(TenantCommand::EXIT_OK, $this->command()->run(['--help']));
        self::assertStringContainsString('php bin/tenant.php create --slug=KÜRZEL', $this->contents($this->out));
        self::assertSame('', $this->contents($this->err));
    }

    /**
     * Ungültige Werte werden vor dem Laden der Konfiguration abgelehnt.
     *
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'Großbuchstaben' => [
            ['create', '--slug=Immendingen', '--name=Feuerwehr Immendingen'],
            'FEHLER: Das Kürzel darf keine Großbuchstaben enthalten. Gemeint ist vermutlich „immendingen“.',
        ];
        yield 'reserviert' => [['create', '--slug=service', '--name=Service'], 'FEHLER: Das Kürzel „service“ ist reserviert.'];
        yield 'ungültiges Format' => [['deactivate', '--slug=a--b'], 'FEHLER: Ungültiges Kürzel'];
        yield 'Name nur Leerzeichen' => [['create', '--slug=a', '--name=   '], 'FEHLER: Der Name darf nicht leer sein.'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidValues')]
    public function testInvalidValuesGiveExitCode1WithoutDatabase(array $args, string $message): void
    {
        $exitCode = $this->command()->run($args);

        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $this->contents($this->out));
        $err = $this->contents($this->err);
        self::assertStringStartsWith($message, $err);
        self::assertStringNotContainsString('Konfigurationsdatei', $err);
    }

    public function testMissingConfigGivesExitCode1(): void
    {
        $exitCode = $this->command()->run(['list']);

        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('FEHLER: Konfigurationsdatei nicht gefunden', $this->contents($this->err));
    }

    private function command(): TenantCommand
    {
        return new TenantCommand('gibt-es-nicht.php', $this->out, $this->err);
    }

    /**
     * @param resource $stream
     */
    private function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
