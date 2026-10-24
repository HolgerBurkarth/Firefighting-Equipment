<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Cli;

use FirefightingEquipment\Cli\MigrateCommand;
use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrateCommand::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConfigException::class)]
final class MigrateCommandTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__ . '/../../..';

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

    public function testParseStatus(): void
    {
        self::assertSame(
            ['command' => 'status', 'dryRun' => false, 'config' => null],
            MigrateCommand::parseArguments(['status']),
        );
    }

    public function testParseRunWithDryRunAndConfig(): void
    {
        self::assertSame(
            ['command' => 'run', 'dryRun' => true, 'config' => 'pfad/zur config.php'],
            MigrateCommand::parseArguments(['--config=pfad/zur config.php', 'run', '--dry-run']),
        );
    }

    public function testParseCheck(): void
    {
        self::assertSame(
            ['command' => 'check', 'dryRun' => false, 'config' => 'a.php'],
            MigrateCommand::parseArguments(['check', '--config=a.php']),
        );
    }

    public function testParseHelp(): void
    {
        self::assertSame('help', MigrateCommand::parseArguments(['--help'])['command']);
        self::assertSame('help', MigrateCommand::parseArguments(['-h'])['command']);
        self::assertSame('help', MigrateCommand::parseArguments(['help'])['command']);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'kein Befehl' => [[], 'Kein Befehl angegeben.'];
        yield 'unbekannter Befehl' => [['migrate'], 'Unbekannter Befehl: migrate'];
        yield 'zwei Befehle' => [['status', 'run'], 'Unerwartetes Argument: run'];
        yield 'unbekannte Option' => [['run', '--force'], 'Unbekannte Option: --force'];
        yield 'dry-run bei status' => [['status', '--dry-run'], '--dry-run ist nur mit run möglich.'];
        yield 'dry-run bei check' => [['check', '--dry-run'], '--dry-run ist nur mit run möglich.'];
        yield 'check und run' => [['check', 'run'], 'Unerwartetes Argument: run'];
        yield 'dry-run doppelt' => [['run', '--dry-run', '--dry-run'], '--dry-run ist doppelt angegeben.'];
        yield 'leerer Pfad' => [['status', '--config='], '--config= braucht einen Pfad.'];
        yield 'config doppelt' => [['status', '--config=a', '--config=b'], '--config ist doppelt angegeben.'];
        yield 'config ohne =' => [['status', '--config', 'a.php'], 'Unbekannte Option: --config'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsAreRejected(array $args, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        MigrateCommand::parseArguments($args);
    }

    public function testInvalidArgumentsGiveExitCode2AndUsage(): void
    {
        $exitCode = $this->command()->run(['unsinn']);

        self::assertSame(MigrateCommand::EXIT_USAGE, $exitCode);
        self::assertStringContainsString('FEHLER: Unbekannter Befehl: unsinn', $this->read($this->err));
        self::assertSame('', $this->read($this->out));
    }

    public function testHelpGivesExitCode0(): void
    {
        self::assertSame(MigrateCommand::EXIT_OK, $this->command()->run(['--help']));
        self::assertStringContainsString('php bin/migrate.php run --dry-run', $this->read($this->out));
    }

    public function testMissingConfigGivesExitCode1(): void
    {
        $exitCode = $this->command()->run(['status', '--config=' . self::PROJECT_DIR . '/config/gibt-es-nicht.php']);

        self::assertSame(MigrateCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('FEHLER: Konfigurationsdatei nicht gefunden', $this->read($this->err));
    }

    public function testCheckWithMissingConfigGivesExitCode1NotPending(): void
    {
        $exitCode = $this->command()->run(['check']);

        self::assertSame(MigrateCommand::EXIT_ERROR, $exitCode);
        self::assertNotSame(MigrateCommand::EXIT_PENDING, $exitCode);
    }

    public function testDefaultConfigPathIsUsed(): void
    {
        $command = new MigrateCommand(
            self::PROJECT_DIR . '/migrations',
            self::PROJECT_DIR . '/config/standard-gibt-es-nicht.php',
            $this->out,
            $this->err,
        );

        self::assertSame(MigrateCommand::EXIT_ERROR, $command->run(['status']));
        self::assertStringContainsString('standard-gibt-es-nicht.php', $this->read($this->err));
    }

    public function testInvalidConfigDoesNotLeakValues(): void
    {
        $exitCode = $this->command()->run(['status', '--config=' . self::PROJECT_DIR . '/tests/Fixtures/config/unknown-key.php']);

        self::assertSame(MigrateCommand::EXIT_ERROR, $exitCode);
        self::assertStringNotContainsString('nicht-ausgeben', $this->read($this->err));
    }

    private function command(): MigrateCommand
    {
        return new MigrateCommand(
            self::PROJECT_DIR . '/migrations',
            self::PROJECT_DIR . '/config/gibt-es-nicht.php',
            $this->out,
            $this->err,
        );
    }

    /**
     * @param resource $stream
     */
    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
