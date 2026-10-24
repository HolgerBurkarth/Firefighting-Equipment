<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Cli;

use FirefightingEquipment\Backup\BackupDirectory;
use FirefightingEquipment\Backup\BackupException;
use FirefightingEquipment\Backup\BackupFileName;
use FirefightingEquipment\Cli\DbDumpCommand;
use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DbDumpCommand::class)]
#[UsesClass(BackupDirectory::class)]
#[UsesClass(BackupException::class)]
#[UsesClass(BackupFileName::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConfigException::class)]
final class DbDumpCommandTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__ . '/../../..';

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
        $this->err = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
        $this->dir = new TempDirectory();
    }

    protected function tearDown(): void
    {
        fclose($this->out);
        fclose($this->err);
        $this->dir->remove();
    }

    public function testParseWithoutArgumentsUsesDefaults(): void
    {
        self::assertSame(
            ['config' => null, 'target-dir' => null, 'suffix' => null, 'mysqldump' => null],
            DbDumpCommand::parseArguments([]),
        );
    }

    public function testParseAllOptions(): void
    {
        self::assertSame(
            [
                'config' => 'pfad/zur config.php',
                'target-dir' => '/home/x/_backup',
                'suffix' => 'vor-migration-81a0d4c',
                'mysqldump' => '/usr/bin/mysqldump',
            ],
            DbDumpCommand::parseArguments([
                '--suffix=vor-migration-81a0d4c',
                '--mysqldump=/usr/bin/mysqldump',
                '--config=pfad/zur config.php',
                '--target-dir=/home/x/_backup',
            ]),
        );
    }

    public function testParseHelp(): void
    {
        self::assertNull(DbDumpCommand::parseArguments(['--help']));
        self::assertNull(DbDumpCommand::parseArguments(['-h']));
        self::assertNull(DbDumpCommand::parseArguments(['help']));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'unbekannte Option' => [['--force'], 'Unbekannte Option: --force'];
        yield 'unbekannte Option mit Wert' => [['--host=x'], 'Unbekannte Option: --host=x'];
        yield 'Argument' => [['run'], 'Unerwartetes Argument: run'];
        yield 'config ohne =' => [['--config', 'a.php'], 'Unbekannte Option: --config'];
        yield 'leerer Pfad' => [['--config='], '--config= braucht einen Wert.'];
        yield 'leerer Zielordner' => [['--target-dir='], '--target-dir= braucht einen Wert.'];
        yield 'doppelt' => [['--suffix=a', '--suffix=b'], '--suffix ist doppelt angegeben.'];
        yield 'ungültiger Zusatz' => [['--suffix=../x'], 'Ungültiger Zusatz "../x"'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsAreRejected(array $args, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        DbDumpCommand::parseArguments($args);
    }

    public function testInvalidArgumentsGiveExitCode2AndUsage(): void
    {
        self::assertSame(DbDumpCommand::EXIT_USAGE, $this->command()->run(['--suffix=Gross']));
        self::assertStringContainsString('FEHLER: Ungültiger Zusatz', $this->read($this->err));
        self::assertStringContainsString('php bin/db-dump.php', $this->read($this->err));
        self::assertSame('', $this->read($this->out));
    }

    public function testHelpGivesExitCode0(): void
    {
        self::assertSame(DbDumpCommand::EXIT_OK, $this->command()->run(['--help']));
        self::assertStringContainsString('--target-dir=PFAD', $this->read($this->out));
    }

    public function testMissingTargetDirGivesExitCode1(): void
    {
        $exitCode = $this->command()->run(['--target-dir=' . $this->dir->file('gibt-es-nicht')]);

        self::assertSame(DbDumpCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('FEHLER: Sicherungsordner existiert nicht', $this->read($this->err));
        self::assertSame('', $this->read($this->out));
    }

    public function testDefaultTargetDirIsUsed(): void
    {
        self::assertSame(DbDumpCommand::EXIT_ERROR, $this->command()->run([]));
        self::assertStringContainsString('standard-ziel-gibt-es-nicht', $this->read($this->err));
    }

    public function testTargetDirWithoutHtaccessGivesExitCode1BeforeLoadingConfig(): void
    {
        $exitCode = $this->command()->run(['--target-dir=' . $this->dir->path]);

        self::assertSame(DbDumpCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('fehlt die .htaccess', $this->read($this->err));
        self::assertStringNotContainsString('Konfigurationsdatei', $this->read($this->err));
        self::assertSame(['.', '..'], scandir($this->dir->path));
    }

    public function testMissingConfigGivesExitCode1AndCreatesNothing(): void
    {
        $this->dir->write('.htaccess', 'Require all denied');

        $exitCode = $this->command()->run(['--target-dir=' . $this->dir->path]);

        self::assertSame(DbDumpCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('FEHLER: Konfigurationsdatei nicht gefunden', $this->read($this->err));
        self::assertSame(['.', '..', '.htaccess'], scandir($this->dir->path));
    }

    public function testInvalidConfigDoesNotLeakValues(): void
    {
        $this->dir->write('.htaccess', 'Require all denied');

        $exitCode = $this->command()->run([
            '--target-dir=' . $this->dir->path,
            '--config=' . self::PROJECT_DIR . '/tests/Fixtures/config/unknown-key.php',
        ]);

        self::assertSame(DbDumpCommand::EXIT_ERROR, $exitCode);
        self::assertStringNotContainsString('nicht-ausgeben', $this->read($this->err));
    }

    private function command(): DbDumpCommand
    {
        return new DbDumpCommand(
            self::PROJECT_DIR . '/config/gibt-es-nicht.php',
            $this->dir->file('standard-ziel-gibt-es-nicht'),
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
