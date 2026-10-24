<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Backup;

use FirefightingEquipment\Backup\BackupException;
use FirefightingEquipment\Backup\BackupFileName;
use FirefightingEquipment\Backup\DatabaseDumper;
use FirefightingEquipment\Backup\MysqlDefaultsFile;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verwendet statt mysqldump ein PHP-Skript (tests/Fixtures/backup/fake-mysqldump.php).
 */
#[CoversClass(DatabaseDumper::class)]
#[UsesClass(BackupFileName::class)]
#[UsesClass(DatabaseConfig::class)]
#[UsesClass(DatabaseError::class)]
#[UsesClass(MysqlDefaultsFile::class)]
final class DatabaseDumperTest extends TestCase
{
    private const string FAKE = __DIR__ . '/../../Fixtures/backup/fake-mysqldump.php';
    private const string FILENAME = 'ff-db-20261024-093000Z-test.sql.gz';
    private const string PASSWORD = 'Geheim-Passwort-42';
    private const string USER = 'benutzer_xyz';
    private const string HOST = 'db-host.example';

    private TempDirectory $dir;
    private DatabaseConfig $config;

    protected function setUp(): void
    {
        $this->dir = new TempDirectory();
        $this->dir->write('.htaccess', 'Require all denied');
        $this->config = new DatabaseConfig(self::HOST, 'firefighting', self::USER, self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testCompleteDumpIsGzipAndPassesArgumentsWithoutCredentials(): void
    {
        $path = $this->dumper('complete')->dump($this->config, ['fe_schema_migrations', 'fe_tenants'], $this->dir->path, self::FILENAME);

        self::assertSame($this->dir->file(self::FILENAME), $path);
        $raw = (string) file_get_contents($path);
        self::assertSame("\x1f\x8b", substr($raw, 0, 2));

        $sql = (string) gzdecode($raw);
        $args = array_map(
            static fn (string $line): string => substr($line, strlen('-- ARG ')),
            array_values(array_filter(explode("\n", $sql), static fn (string $line): bool => str_starts_with($line, '-- ARG '))),
        );

        self::assertMatchesRegularExpression('/^--defaults-extra-file=.+\.cnf$/', $args[0]);
        self::assertSame(
            [...DatabaseDumper::OPTIONS, '--', 'firefighting', 'fe_schema_migrations', 'fe_tenants'],
            array_slice($args, 1),
        );
        self::assertStringContainsString('-- DEFAULTS vorhanden', $sql);
        self::assertStringContainsString("VALUES ('Müllheim')", $sql);
        foreach ([self::PASSWORD, self::USER, self::HOST] as $secret) {
            self::assertStringNotContainsString($secret, implode(' ', $args));
        }

        self::assertSame(['.htaccess', self::FILENAME], $this->files());
    }

    public function testPermissionsAre600(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Unix-Dateirechte sind unter Windows nicht prüfbar.');
        }

        $path = $this->dumper('complete')->dump($this->config, ['fe_tenants'], $this->dir->path, self::FILENAME);
        clearstatcache();

        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function testLargeOutputIsStreamedCompletely(): void
    {
        $path = $this->dumper('large')->dump($this->config, ['fe_tenants'], $this->dir->path, self::FILENAME);

        $sql = (string) gzdecode((string) file_get_contents($path));
        self::assertSame(20001, substr_count($sql, "\n"));
        self::assertStringContainsString("VALUES (19999, '", $sql);
        self::assertTrue(DatabaseDumper::isComplete($sql));
    }

    public function testIncompleteDumpIsRejectedAndDeleted(): void
    {
        $e = $this->expectFailure('incomplete');

        self::assertStringContainsString('Sicherung ist unvollständig', $e->getMessage());
        self::assertSame(['.htaccess'], $this->files());
    }

    public function testFailingMysqldumpGivesMaskedMessageAndLeavesNothingBehind(): void
    {
        $e = $this->expectFailure('fail');

        self::assertStringContainsString('mysqldump ist fehlgeschlagen (Exit-Code 2)', $e->getMessage());
        self::assertStringContainsString('Access denied', $e->getMessage());
        foreach ([self::PASSWORD, self::USER, self::HOST] as $secret) {
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
        self::assertSame(['.htaccess'], $this->files());
    }

    public function testMissingProgramGivesErrorAndLeavesNothingBehind(): void
    {
        $dumper = new DatabaseDumper([$this->dir->file('gibt-es-nicht-mysqldump')]);

        try {
            $dumper->dump($this->config, ['fe_tenants'], $this->dir->path, self::FILENAME);
            self::fail('BackupException erwartet.');
        } catch (BackupException $e) {
            self::assertMatchesRegularExpression('/konnte nicht gestartet werden|fehlgeschlagen \(Exit-Code \d+\)/', $e->getMessage());
        }
        self::assertSame(['.htaccess'], $this->files());
    }

    public function testEmptyTableListIsRejected(): void
    {
        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Keine Tabellen fe_* vorhanden');

        $this->dumper('complete')->dump($this->config, [], $this->dir->path, self::FILENAME);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTables(): iterable
    {
        yield 'ohne Präfix' => ['users'];
        yield 'Option' => ['--all-databases'];
        yield 'Großbuchstaben' => ['fe_Tenants'];
        yield 'Leerzeichen' => ['fe_a b'];
    }

    #[DataProvider('invalidTables')]
    public function testInvalidTableNameIsRejected(string $table): void
    {
        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Ungültiger Tabellenname');

        $this->dumper('complete')->dump($this->config, ['fe_tenants', $table], $this->dir->path, self::FILENAME);
    }

    public function testInvalidFilenameIsRejected(): void
    {
        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Ungültiger Dateiname');

        $this->dumper('complete')->dump($this->config, ['fe_tenants'], $this->dir->path, '../ff-db.sql.gz');
    }

    public function testExistingBackupIsNotOverwritten(): void
    {
        $this->dir->write(self::FILENAME, 'alt');

        try {
            $this->dumper('complete')->dump($this->config, ['fe_tenants'], $this->dir->path, self::FILENAME);
            self::fail('BackupException erwartet.');
        } catch (BackupException $e) {
            self::assertStringContainsString('existiert bereits', $e->getMessage());
        }
        self::assertSame('alt', file_get_contents($this->dir->file(self::FILENAME)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function tails(): iterable
    {
        yield 'Abschlusszeile' => ["INSERT ...;\n-- Dump completed on 2026-10-24  9:30:00\n", true];
        yield 'Abschlusszeile mit CRLF und Leerzeilen' => ["x\r\n-- Dump completed on 2026-10-24\r\n\r\n", true];
        yield 'Abschlusszeile ohne Zeilenende' => ['-- Dump completed', true];
        yield 'fehlt' => ["CREATE TABLE x (id INT);\n", false];
        yield 'nicht die letzte Zeile' => ["-- Dump completed\nINSERT ...;\n", false];
        yield 'leer' => ['', false];
    }

    #[DataProvider('tails')]
    public function testIsComplete(string $tail, bool $expected): void
    {
        self::assertSame($expected, DatabaseDumper::isComplete($tail));
    }

    public function testMaskRemovesCredentials(): void
    {
        $message = sprintf(
            "Got error: 1045: \"Access denied for user '%s'@'%s' (using password: YES)\" pw=%s",
            self::USER,
            self::HOST,
            self::PASSWORD,
        );

        $masked = DatabaseDumper::mask($message, $this->config);

        foreach ([self::PASSWORD, self::USER, self::HOST] as $secret) {
            self::assertStringNotContainsString($secret, $masked);
        }
        self::assertStringContainsString('Access denied', $masked);
    }

    private function dumper(string $mode): DatabaseDumper
    {
        return new DatabaseDumper([PHP_BINARY, self::FAKE, $mode]);
    }

    private function expectFailure(string $mode): BackupException
    {
        try {
            $this->dumper($mode)->dump($this->config, ['fe_tenants'], $this->dir->path, self::FILENAME);
        } catch (BackupException $e) {
            return $e;
        }

        self::fail('BackupException erwartet.');
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = array_values(array_diff(scandir($this->dir->path) ?: [], ['.', '..']));
        sort($files);

        return $files;
    }
}
