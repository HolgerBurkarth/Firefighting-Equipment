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
            ['command' => 'list', 'slug' => null, 'name' => null, 'email' => null, 'config' => null, 'confirm' => null, 'print_link' => false, 'execute' => false],
            TenantCommand::parseArguments(['list']),
        );
    }

    public function testParseCreateWithConfig(): void
    {
        self::assertSame(
            ['command' => 'create', 'slug' => 'immendingen', 'name' => 'Feuerwehr Immendingen', 'email' => null, 'config' => 'pfad/zur config.php', 'confirm' => null, 'print_link' => false, 'execute' => false],
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

    public function testParseRename(): void
    {
        self::assertSame(
            ['command' => 'rename', 'slug' => 'a', 'name' => 'Neu', 'email' => null, 'config' => null, 'confirm' => null, 'print_link' => false, 'execute' => false],
            TenantCommand::parseArguments(['rename', '--slug=a', '--name=Neu']),
        );
    }

    public function testParseInviteAdmin(): void
    {
        self::assertSame(
            ['command' => 'invite-admin', 'slug' => 'a', 'name' => null, 'email' => 'x@example.org', 'config' => null, 'confirm' => null, 'print_link' => false, 'execute' => false],
            TenantCommand::parseArguments(['invite-admin', '--slug=a', '--email=x@example.org']),
        );
        self::assertTrue(TenantCommand::parseArguments(['--print-link', 'invite-admin', '--slug=a', '--email=x@example.org'])['print_link']);
    }

    public function testParsePurgeDeviceData(): void
    {
        self::assertSame(
            ['command' => 'purge-device-data', 'slug' => 'a', 'name' => null, 'email' => null, 'config' => null, 'confirm' => null, 'print_link' => false, 'execute' => false],
            TenantCommand::parseArguments(['purge-device-data', '--slug=a']),
        );
        self::assertSame(
            ['command' => 'purge-device-data', 'slug' => 'a', 'name' => null, 'email' => null, 'config' => null, 'confirm' => 'a', 'print_link' => false, 'execute' => true],
            TenantCommand::parseArguments(['--confirm=a', 'purge-device-data', '--execute', '--slug=a']),
        );
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
        yield 'rename ohne name' => [['rename', '--slug=a'], 'rename braucht --name="NAME".'];
        yield 'rename ohne slug' => [['rename', '--name=X'], 'rename braucht --slug=KÜRZEL.'];
        yield 'invite-admin ohne email' => [['invite-admin', '--slug=a'], 'invite-admin braucht --email=ADRESSE.'];
        yield 'invite-admin ohne slug' => [['invite-admin', '--email=x@example.org'], 'invite-admin braucht --slug=KÜRZEL.'];
        yield 'invite-admin mit name' => [['invite-admin', '--slug=a', '--email=x@example.org', '--name=X'], '--name ist bei invite-admin nicht möglich.'];
        yield 'create mit email' => [['create', '--slug=a', '--name=X', '--email=x@example.org'], '--email ist bei create nicht möglich.'];
        yield 'print-link ohne invite-admin' => [['activate', '--slug=a', '--print-link'], '--print-link ist bei activate nicht möglich.'];
        yield 'print-link doppelt' => [['invite-admin', '--slug=a', '--email=x@example.org', '--print-link', '--print-link'], '--print-link ist doppelt angegeben.'];
        yield 'email leer' => [['invite-admin', '--slug=a', '--email='], '--email= braucht einen Wert.'];
        yield 'unbekannte Option --link' => [['invite-admin', '--slug=a', '--email=x@example.org', '--link'], 'Unbekannte Option: --link'];
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
        yield 'purge ohne slug' => [['purge-device-data'], 'purge-device-data braucht --slug=KÜRZEL.'];
        yield 'purge ohne confirm' => [['purge-device-data', '--slug=a', '--execute'], '--execute braucht --confirm=KÜRZEL'];
        yield 'purge confirm ohne execute' => [['purge-device-data', '--slug=a', '--confirm=a'], '--confirm ist nur zusammen mit --execute möglich.'];
        yield 'purge falsches confirm' => [['purge-device-data', '--slug=a', '--execute', '--confirm=b'], '--confirm stimmt nicht mit --slug überein.'];
        yield 'purge confirm in anderer Schreibweise' => [['purge-device-data', '--slug=a', '--execute', '--confirm=A'], '--confirm stimmt nicht mit --slug überein.'];
        yield 'purge confirm mit Leerzeichen' => [['purge-device-data', '--slug=a', '--execute', '--confirm=a '], '--confirm stimmt nicht mit --slug überein.'];
        yield 'purge confirm leer' => [['purge-device-data', '--slug=a', '--execute', '--confirm='], '--confirm= braucht einen Wert.'];
        yield 'purge execute doppelt' => [['purge-device-data', '--slug=a', '--execute', '--execute', '--confirm=a'], '--execute ist doppelt angegeben.'];
        yield 'purge confirm doppelt' => [['purge-device-data', '--slug=a', '--execute', '--confirm=a', '--confirm=a'], '--confirm ist doppelt angegeben.'];
        yield 'purge mit name' => [['purge-device-data', '--slug=a', '--name=X'], '--name ist bei purge-device-data nicht möglich.'];
        yield 'execute bei deactivate' => [['deactivate', '--slug=a', '--execute', '--confirm=a'], '--execute ist bei deactivate nicht möglich.'];
        yield 'confirm bei list' => [['list', '--confirm=a'], '--confirm ist bei list nicht möglich.'];
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
        self::assertStringContainsString('php bin/tenant.php rename --slug=KÜRZEL', $this->contents($this->out));
        self::assertStringContainsString('php bin/tenant.php invite-admin --slug=KÜRZEL --email=ADRESSE [--print-link]', $this->contents($this->out));
        self::assertStringContainsString('php bin/tenant.php purge-device-data --slug=KÜRZEL [--execute --confirm=KÜRZEL]', $this->contents($this->out));
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
        yield 'neuer Name nur Leerzeichen' => [['rename', '--slug=a', '--name=   '], 'FEHLER: Der Name darf nicht leer sein.'];
        yield 'ungültige E-Mail' => [['invite-admin', '--slug=a', '--email=keine-adresse'], 'FEHLER: Ungültige E-Mail-Adresse'];
        yield 'purge service' => [['purge-device-data', '--slug=service'], 'FEHLER: Das Kürzel „service“ ist reserviert.'];
        yield 'purge service mit execute' => [['purge-device-data', '--slug=service', '--execute', '--confirm=service'], 'FEHLER: Das Kürzel „service“ ist reserviert.'];
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
