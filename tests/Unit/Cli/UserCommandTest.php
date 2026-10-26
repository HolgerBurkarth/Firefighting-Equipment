<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Cli;

use FirefightingEquipment\Cli\TerminalPasswordPrompt;
use FirefightingEquipment\Cli\UserCommand;
use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Tenant\InvalidTenantValueException;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\InvalidUserValueException;
use FirefightingEquipment\User\Password\PasswordHasher;
use FirefightingEquipment\User\PersonName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserCommand::class)]
#[UsesClass(ConfigException::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(EmailAddress::class)]
#[UsesClass(InvalidTenantValueException::class)]
#[UsesClass(InvalidUserValueException::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(TenantSlug::class)]
#[UsesClass(TerminalPasswordPrompt::class)]
#[UsesClass(PasswordHasher::class)]
final class UserCommandTest extends TestCase
{
    /** @var resource */
    private $in;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        $this->in = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
        $this->out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
        $this->err = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
    }

    protected function tearDown(): void
    {
        fclose($this->in);
        fclose($this->out);
        fclose($this->err);
    }

    public function testUsesDefaultArgon2idParametersLikeBinUser(): void
    {
        $hasher = new \ReflectionProperty(UserCommand::class, 'hasher')->getValue($this->command());

        self::assertInstanceOf(PasswordHasher::class, $hasher);
        self::assertFalse($hasher->needsRehash(PasswordHasher::DEFAULT_DUMMY_HASH));
    }

    public function testParseCreate(): void
    {
        self::assertSame(
            [
                'command' => 'superadmin:create',
                'email' => 'chef@example.org',
                'name' => 'Erika Musterfrau',
                'tenant' => null,
                'config' => 'pfad/zur config.php',
                'confirm' => null,
                'passwordStdin' => true,
            ],
            UserCommand::parseArguments([
                '--name=Erika Musterfrau',
                'superadmin:create',
                '--password-stdin',
                '--config=pfad/zur config.php',
                '--email=chef@example.org',
            ]),
        );
    }

    public function testParseList(): void
    {
        self::assertSame(
            ['command' => 'list', 'email' => null, 'name' => null, 'tenant' => 'immendingen', 'config' => null, 'confirm' => null, 'passwordStdin' => false],
            UserCommand::parseArguments(['list', '--tenant=immendingen']),
        );
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function validCalls(): iterable
    {
        yield 'superadmin:list' => [['superadmin:list']];
        yield 'superadmin:password' => [['superadmin:password', '--email=a@b.de']];
        yield 'superadmin:password mit stdin' => [['superadmin:password', '--email=a@b.de', '--password-stdin']];
        yield 'superadmin:lock' => [['superadmin:lock', '--email=a@b.de']];
        yield 'superadmin:unlock' => [['superadmin:unlock', '--email=a@b.de']];
        yield 'superadmin:protect' => [['superadmin:protect', '--email=a@b.de']];
        yield 'superadmin:unprotect' => [['superadmin:unprotect', '--email=a@b.de', '--confirm=a@b.de']];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('validCalls')]
    public function testValidCalls(array $args): void
    {
        self::assertSame($args[0], UserCommand::parseArguments($args)['command']);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidCalls(): iterable
    {
        yield 'kein Befehl' => [[], 'Kein Befehl angegeben.'];
        yield 'unbekannter Befehl' => [['superadmin:delete'], 'Unbekannter Befehl: superadmin:delete'];
        yield 'zwei Befehle' => [['superadmin:list', 'list'], 'Unerwartetes weiteres Argument.'];
        yield 'create ohne Name' => [['superadmin:create', '--email=a@b.de'], 'superadmin:create braucht --name="NAME".'];
        yield 'create ohne E-Mail' => [['superadmin:create', '--name=Erika'], 'superadmin:create braucht --email=ADRESSE.'];
        yield 'lock ohne E-Mail' => [['superadmin:lock'], 'superadmin:lock braucht --email=ADRESSE.'];
        yield 'list ohne Stadt' => [['list'], 'list braucht --tenant=KÜRZEL.'];
        yield 'unprotect ohne Bestätigung' => [
            ['superadmin:unprotect', '--email=a@b.de'],
            'superadmin:unprotect braucht --confirm=ADRESSE (zur Bestätigung dieselbe Adresse wie bei --email).',
        ];
        yield 'unprotect mit anderer Bestätigung' => [
            ['superadmin:unprotect', '--email=a@b.de', '--confirm=A@b.de'],
            '--confirm muss genau dieselbe Adresse wie --email enthalten.',
        ];
        yield 'Bestätigung bei protect' => [
            ['superadmin:protect', '--email=a@b.de', '--confirm=a@b.de'],
            '--confirm ist bei superadmin:protect nicht möglich.',
        ];
        yield 'Bestätigung doppelt' => [
            ['superadmin:unprotect', '--email=a@b.de', '--confirm=a@b.de', '--confirm=a@b.de'],
            '--confirm ist doppelt angegeben.',
        ];
        yield 'Name bei lock' => [['superadmin:lock', '--email=a@b.de', '--name=X'], '--name ist bei superadmin:lock nicht möglich.'];
        yield 'E-Mail bei list' => [['list', '--tenant=x', '--email=a@b.de'], '--email ist bei list nicht möglich.'];
        yield 'Stadt bei superadmin:list' => [['superadmin:list', '--tenant=x'], '--tenant ist bei superadmin:list nicht möglich.'];
        yield 'stdin bei lock' => [['superadmin:lock', '--email=a@b.de', '--password-stdin'], '--password-stdin ist bei superadmin:lock nicht möglich.'];
        yield 'stdin doppelt' => [['superadmin:password', '--email=a@b.de', '--password-stdin', '--password-stdin'], '--password-stdin ist doppelt'];
        yield 'E-Mail doppelt' => [['superadmin:lock', '--email=a@b.de', '--email=c@d.de'], '--email ist doppelt angegeben.'];
        yield 'leerer Wert' => [['superadmin:lock', '--email='], '--email= braucht einen Wert.'];
        yield 'leere Konfiguration' => [['superadmin:list', '--config='], '--config= braucht einen Pfad.'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidCalls')]
    public function testInvalidCallGivesExitCode2(array $args, string $message): void
    {
        self::assertSame(UserCommand::EXIT_USAGE, $this->command()->run($args));
        self::assertStringContainsString('FEHLER: ' . $message, $this->err());
        self::assertStringContainsString('Aufruf:', $this->err());
        self::assertSame('', $this->out());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function passwordArguments(): iterable
    {
        yield '--password=' => ['--password=Geheim-Pferd-42'];
        yield '-password=' => ['-password=Geheim-Pferd-42'];
        yield '--PASSWORD=' => ['--PASSWORD=Geheim-Pferd-42'];
    }

    #[DataProvider('passwordArguments')]
    public function testPasswordArgumentIsRefusedWithoutEchoingIt(string $arg): void
    {
        self::assertSame(UserCommand::EXIT_USAGE, $this->command()->run(['superadmin:password', '--email=a@b.de', $arg]));
        self::assertStringContainsString('Passwörter werden nie als Argument übergeben', $this->err());
        self::assertStringNotContainsString('Geheim-Pferd-42', $this->err());
    }

    public function testUnknownOptionValueIsNotEchoed(): void
    {
        self::assertSame(UserCommand::EXIT_USAGE, $this->command()->run(['superadmin:list', '--pw=Geheim-Pferd-42']));
        self::assertStringContainsString('FEHLER: Unbekannte Option: --pw' . PHP_EOL, $this->err());
        self::assertStringNotContainsString('Geheim-Pferd-42', $this->err());
    }

    public function testHelp(): void
    {
        self::assertSame(UserCommand::EXIT_OK, $this->command()->run(['--help']));
        self::assertStringContainsString('php bin/user.php superadmin:create', $this->out());
        self::assertStringContainsString('--password-stdin', $this->out());
        self::assertSame('', $this->err());
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'E-Mail' => [['superadmin:lock', '--email=keine-adresse'], 'Ungültige E-Mail-Adresse'];
        yield 'Name' => [['superadmin:create', '--email=a@b.de', "--name=Max\nMuster"], 'Der Name darf keine Steuerzeichen'];
        yield 'Kürzel' => [['list', '--tenant=Immendingen'], 'Das Kürzel darf keine Großbuchstaben enthalten.'];
    }

    /**
     * Ungültige Werte werden vor dem Laden der Konfiguration erkannt.
     *
     * @param list<string> $args
     */
    #[DataProvider('invalidValues')]
    public function testInvalidValueGivesExitCode1BeforeConnecting(array $args, string $message): void
    {
        self::assertSame(UserCommand::EXIT_ERROR, $this->command()->run($args));
        self::assertStringContainsString('FEHLER: ' . $message, $this->err());
        self::assertStringNotContainsString('Konfiguration', $this->err());
    }

    public function testMissingConfigGivesExitCode1(): void
    {
        self::assertSame(UserCommand::EXIT_ERROR, $this->command()->run(['superadmin:list']));
        self::assertSame('FEHLER: Konfigurationsdatei nicht gefunden oder nicht lesbar: gibt-es-nicht.php' . PHP_EOL, $this->err());
    }

    private function command(): UserCommand
    {
        return new UserCommand('gibt-es-nicht.php', $this->in, $this->out, $this->err);
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
