<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Migration;

use FirefightingEquipment\Cli\MigrateCommand;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Migration\AppliedMigration;
use FirefightingEquipment\Migration\Checksum;
use FirefightingEquipment\Migration\MigrationDirectory;
use FirefightingEquipment\Migration\MigrationException;
use FirefightingEquipment\Migration\MigrationFile;
use FirefightingEquipment\Migration\MigrationLock;
use FirefightingEquipment\Migration\MigrationLog;
use FirefightingEquipment\Migration\MigrationStatus;
use FirefightingEquipment\Migration\MigrationVerifier;
use FirefightingEquipment\Migration\Migrator;
use FirefightingEquipment\Migration\SqlSplitter;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(MigrateCommand::class)]
#[UsesClass(AppliedMigration::class)]
#[UsesClass(Checksum::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConnectionFactory::class)]
#[UsesClass(DatabaseConfig::class)]
#[UsesClass(DatabaseError::class)]
#[UsesClass(MigrationDirectory::class)]
#[UsesClass(MigrationException::class)]
#[UsesClass(MigrationFile::class)]
#[UsesClass(MigrationLock::class)]
#[UsesClass(MigrationLog::class)]
#[UsesClass(MigrationStatus::class)]
#[UsesClass(MigrationVerifier::class)]
#[UsesClass(Migrator::class)]
#[UsesClass(SqlSplitter::class)]
final class MigrateCommandTest extends MigrationTestCase
{
    public function testStatusShowsPendingAndCreatesNothing(): void
    {
        [$exitCode, $out] = $this->runCommand('valid', ['status']);

        self::assertSame(MigrateCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString('Protokolltabelle: fe_test_schema_migrations (noch nicht angelegt)', $out);
        self::assertStringContainsString('[ ] 0001_create_fe_test_items.sql   offen', $out);
        self::assertStringContainsString('[ ] 0002_create_fe_test_places.sql  offen', $out);
        self::assertStringContainsString('0 ausgeführt, 2 offen.', $out);
        self::assertFalse($this->tableExists(self::TABLE));
    }

    public function testDryRunShowsPlanAndCreatesNothing(): void
    {
        [$exitCode, $out] = $this->runCommand('valid', ['run', '--dry-run']);

        self::assertSame(MigrateCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString('Trockenlauf: Es wird nichts ausgeführt.', $out);
        self::assertStringContainsString('Würde Protokolltabelle fe_test_schema_migrations anlegen.', $out);
        self::assertStringContainsString('Würde ausführen: 0001_create_fe_test_items.sql (2 Anweisungen)', $out);
        self::assertStringContainsString('Würde ausführen: 0002_create_fe_test_places.sql (1 Anweisung)', $out);
        self::assertFalse($this->tableExists(self::TABLE));
        self::assertFalse($this->tableExists('fe_test_items'));
    }

    public function testRunThenStatus(): void
    {
        [$exitCode, $out] = $this->runCommand('valid', ['run']);

        self::assertSame(MigrateCommand::EXIT_OK, $exitCode);
        self::assertMatchesRegularExpression('/^OK  0001_create_fe_test_items\.sql \(\d+ ms\)\r?$/m', $out);
        self::assertStringContainsString('2 Migration(en) ausgeführt.', $out);

        [, $out] = $this->runCommand('valid', ['status']);
        self::assertStringContainsString('Protokolltabelle: fe_test_schema_migrations' . PHP_EOL, $out);
        self::assertMatchesRegularExpression('/^\[x\] 0001_create_fe_test_items\.sql   \S+ \S+ UTC  \d+ ms\r?$/m', $out);
        self::assertStringContainsString('2 ausgeführt, 0 offen.', $out);

        [, $out] = $this->runCommand('valid', ['run']);
        self::assertStringContainsString('Keine offenen Migrationen.', $out);

        [, $out] = $this->runCommand('valid', ['run', '--dry-run']);
        self::assertStringContainsString('Keine offenen Migrationen.', $out);
    }

    public function testCheckReportsPendingWithExitCode3AndCreatesNothing(): void
    {
        [$exitCode, $out, $err] = $this->runCommand('valid', ['check']);

        self::assertSame(MigrateCommand::EXIT_PENDING, $exitCode);
        self::assertSame('2 offene Migration(en).' . PHP_EOL, $out);
        self::assertSame('', $err);
        self::assertFalse($this->tableExists(self::TABLE));
        self::assertFalse($this->tableExists('fe_test_items'));
    }

    public function testCheckAfterRunGivesExitCode0(): void
    {
        $this->runCommand('valid', ['run']);

        [$exitCode, $out] = $this->runCommand('valid', ['check']);

        self::assertSame(MigrateCommand::EXIT_OK, $exitCode);
        self::assertSame('Keine offenen Migrationen.' . PHP_EOL, $out);
    }

    public function testCheckAfterPartialRunGivesExitCode3(): void
    {
        $this->runCommand('broken', ['run']);

        [$exitCode, $out] = $this->runCommand('broken', ['check']);

        self::assertSame(MigrateCommand::EXIT_PENDING, $exitCode);
        self::assertSame('2 offene Migration(en).' . PHP_EOL, $out);
    }

    public function testFailingMigrationGivesExitCode1(): void
    {
        [$exitCode, $out, $err] = $this->runCommand('broken', ['run']);

        self::assertSame(MigrateCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('OK  0001_create_fe_test_items.sql', $out);
        self::assertStringContainsString('FEHLER: Migration 0002_insert_duplicate.sql fehlgeschlagen', $err);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runCommand(string $fixture, array $args): array
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
        $err = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');

        $command = new MigrateCommand(self::FIXTURES . $fixture, TestDatabase::CONFIG_PATH, $out, $err, self::TABLE);
        $exitCode = $command->run($args);

        rewind($out);
        rewind($err);
        $result = [$exitCode, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        fclose($out);
        fclose($err);

        return $result;
    }
}
