<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Backup;

use FirefightingEquipment\Backup\BackupDirectory;
use FirefightingEquipment\Backup\BackupFileName;
use FirefightingEquipment\Backup\BackupRotation;
use FirefightingEquipment\Backup\BackupTables;
use FirefightingEquipment\Backup\DatabaseDumper;
use FirefightingEquipment\Backup\MysqlDefaultsFile;
use FirefightingEquipment\Cli\DbDumpCommand;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Integration\DomainTestCase;
use FirefightingEquipment\Tests\Support\Mysqldump;
use FirefightingEquipment\Tests\Support\TempDirectory;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Sichert die Testdatenbank mit dem echten mysqldump in einen temporären Ordner.
 */
#[CoversClass(DbDumpCommand::class)]
#[CoversClass(DatabaseDumper::class)]
#[CoversClass(BackupTables::class)]
#[UsesClass(BackupDirectory::class)]
#[UsesClass(BackupFileName::class)]
#[UsesClass(BackupRotation::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConnectionFactory::class)]
#[UsesClass(DatabaseConfig::class)]
#[UsesClass(MysqlDefaultsFile::class)]
final class DbDumpTest extends DomainTestCase
{
    private TempDirectory $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!Mysqldump::available()) {
            self::markTestSkipped('mysqldump nicht im PATH.');
        }
        $this->dir = new TempDirectory();
        $this->dir->write('.htaccess', 'Require all denied');
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->dir->remove();
        }
        parent::tearDown();
    }

    public function testBackupTablesListsOnlyFeTables(): void
    {
        $tables = BackupTables::list($this->pdo());

        self::assertContains('fe_tenants', $tables);
        self::assertContains('fe_schema_migrations', $tables);
        foreach ($tables as $table) {
            self::assertStringStartsWith('fe_', $table);
        }
    }

    public function testDumpOfTestDatabase(): void
    {
        new TenantRepository($this->pdo())->create(
            TenantSlug::fromString('muellheim'),
            TenantName::fromString('Feuerwehr Müllheim'),
        );

        [$exitCode, $out, $err] = $this->runCommand(['--suffix=vor-migration-81a0d4c']);

        self::assertSame(DbDumpCommand::EXIT_OK, $exitCode, $err);
        $path = trim($out);
        self::assertSame(realpath($this->dir->path), dirname($path));
        self::assertMatchesRegularExpression('/^ff-db-\d{8}-\d{6}Z-vor-migration-81a0d4c\.sql\.gz$/', basename($path));

        $raw = (string) file_get_contents($path);
        self::assertSame("\x1f\x8b", substr($raw, 0, 2), 'Keine gzip-Datei.');
        $sql = gzdecode($raw);
        self::assertIsString($sql);
        self::assertStringContainsString('CREATE TABLE `fe_tenants`', $sql);
        self::assertStringContainsString('Feuerwehr Müllheim', $sql);
        self::assertTrue(DatabaseDumper::isComplete($sql), 'Abschlusszeile fehlt.');
        self::assertStringNotContainsString('CREATE DATABASE', $sql);

        $config = TestDatabase::config();
        if ($config->pass !== '') {
            self::assertStringNotContainsString($config->pass, $sql);
        }

        // Nur .htaccess und die Sicherung; keine Zugangs-, Fehler- oder .part-Datei.
        self::assertSame(['.', '..', '.htaccess', basename($path)], scandir($this->dir->path));

        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache();
            self::assertSame(0600, fileperms($path) & 0777);
        }
    }

    public function testOldBackupsAreRotated(): void
    {
        // Die Namen sortieren alle hinter der neuen Sicherung; maßgeblich ist das ältere Änderungsdatum.
        for ($day = 1; $day <= BackupRotation::KEEP; $day++) {
            $name = sprintf('ff-db-209901%02d-000000Z.sql.gz', $day);
            $this->dir->write($name, 'alt');
            touch($this->dir->file($name), time() - 86_400 * (20 - $day));
        }
        $this->dir->write('fremd.txt', 'bleibt');

        [$exitCode, $out, $err] = $this->runCommand([]);

        self::assertSame(DbDumpCommand::EXIT_OK, $exitCode, $err);
        self::assertFileDoesNotExist($this->dir->file('ff-db-20990101-000000Z.sql.gz'));
        self::assertFileExists($this->dir->file('ff-db-20990102-000000Z.sql.gz'));
        self::assertFileExists($this->dir->file('ff-db-20990110-000000Z.sql.gz'));
        self::assertFileExists($this->dir->file('fremd.txt'));
        self::assertFileExists(trim($out));
        self::assertStringContainsString('Lösche alte Sicherung:', $err);
    }

    public function testMissingProgramGivesExitCode1AndNoBackup(): void
    {
        [$exitCode, $out, $err] = $this->runCommand(['--mysqldump=' . $this->dir->file('gibt-es-nicht')]);

        self::assertSame(DbDumpCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $out);
        self::assertStringStartsWith('FEHLER: ', $err);
        self::assertSame(['.', '..', '.htaccess'], scandir($this->dir->path));
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runCommand(array $args): array
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
        $err = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');

        $command = new DbDumpCommand(TestDatabase::CONFIG_PATH, $this->dir->path, $out, $err);
        $exitCode = $command->run($args);

        rewind($out);
        rewind($err);
        $result = [$exitCode, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        fclose($out);
        fclose($err);

        return $result;
    }
}
