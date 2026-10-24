<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Backup;

use FirefightingEquipment\Backup\BackupException;
use FirefightingEquipment\Backup\MysqlDefaultsFile;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Tests\Support\Mysqldump;
use FirefightingEquipment\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MysqlDefaultsFile::class)]
#[UsesClass(DatabaseConfig::class)]
final class MysqlDefaultsFileTest extends TestCase
{
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = new TempDirectory();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testRenderQuotesAndEscapes(): void
    {
        $config = new DatabaseConfig('db.example', 'name', 'benutzer', 'a\\b"c#d\'e ;f ');

        self::assertSame(
            "[client]\nuser=\"benutzer\"\npassword=\"a\\\\b\\\"c#d'e ;f \"\nhost=\"db.example\"\n",
            MysqlDefaultsFile::render($config),
        );
    }

    public function testEmptyPasswordIsAllowed(): void
    {
        self::assertStringContainsString("password=\"\"\n", MysqlDefaultsFile::render(new DatabaseConfig('h', 'n', 'u', '')));
    }

    /**
     * @return iterable<string, array{DatabaseConfig, string}>
     */
    public static function controlCharacters(): iterable
    {
        yield 'Zeilenumbruch im Passwort' => [new DatabaseConfig('h', 'n', 'u', "geheim\n[mysqld]"), "'db.pass'"];
        yield 'Wagenrücklauf im Benutzer' => [new DatabaseConfig('h', 'n', "u\r", 'p'), "'db.user'"];
        yield 'Nullbyte im Host' => [new DatabaseConfig("h\0", 'n', 'u', 'p'), "'db.host'"];
    }

    #[DataProvider('controlCharacters')]
    public function testControlCharactersAreRejectedWithoutShowingTheValue(DatabaseConfig $config, string $key): void
    {
        try {
            MysqlDefaultsFile::render($config);
            self::fail('BackupException erwartet.');
        } catch (BackupException $e) {
            self::assertStringContainsString($key, $e->getMessage());
            self::assertStringNotContainsString('geheim', $e->getMessage());
        }
    }

    public function testCreateWritesFileInDirectoryAndDeleteRemovesIt(): void
    {
        $config = new DatabaseConfig('h', 'n', 'u', 'geheim');
        $file = MysqlDefaultsFile::create($this->dir->path, $config);

        self::assertSame($this->dir->path, dirname($file->path));
        self::assertStringStartsWith(MysqlDefaultsFile::PREFIX, basename($file->path));
        self::assertStringEndsWith('.cnf', $file->path);
        self::assertSame(MysqlDefaultsFile::render($config), file_get_contents($file->path));

        $file->delete();
        self::assertFileDoesNotExist($file->path);
        // Doppeltes Löschen ist unschädlich.
        $file->delete();
    }

    public function testEachFileGetsItsOwnName(): void
    {
        $config = new DatabaseConfig('h', 'n', 'u', 'p');
        $first = MysqlDefaultsFile::create($this->dir->path, $config);
        $second = MysqlDefaultsFile::create($this->dir->path, $config);

        self::assertNotSame($first->path, $second->path);
        $first->delete();
        $second->delete();
    }

    public function testFileIsDeletedEvenIfAnExceptionOccursInBetween(): void
    {
        $path = null;
        try {
            $file = MysqlDefaultsFile::create($this->dir->path, new DatabaseConfig('h', 'n', 'u', 'p'));
            $path = $file->path;
            try {
                throw new \RuntimeException('Fehler während der Sicherung');
            } finally {
                $file->delete();
            }
        } catch (\RuntimeException) {
        }

        self::assertNotNull($path);
        self::assertFileDoesNotExist($path);
    }

    public function testPermissionsAre600(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Unix-Dateirechte sind unter Windows nicht prüfbar.');
        }

        $file = MysqlDefaultsFile::create($this->dir->path, new DatabaseConfig('h', 'n', 'u', 'p'));
        clearstatcache();

        self::assertSame(0600, fileperms($file->path) & 0777);
        $file->delete();
    }

    public function testCreateFailsForMissingDirectory(): void
    {
        $this->expectException(BackupException::class);

        MysqlDefaultsFile::create($this->dir->file('gibt-es-nicht'), new DatabaseConfig('h', 'n', 'u', 'p'));
    }

    /**
     * Prüft die Maskierung mit dem echten mysqldump (--print-defaults baut keine Verbindung auf).
     */
    public function testMysqldumpReadsValuesUnchanged(): void
    {
        if (!Mysqldump::available()) {
            self::markTestSkipped('mysqldump nicht im PATH.');
        }

        $password = 'a\\b"c#d\'e $x ;y \\n ';
        $file = MysqlDefaultsFile::create($this->dir->path, new DatabaseConfig('db host', 'n', 'be"nutzer', $password));
        try {
            $process = proc_open(
                ['mysqldump', '--defaults-extra-file=' . $file->path, '--print-defaults'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $output = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            $file->delete();
        }

        self::assertStringContainsString('--user=be"nutzer --password=' . $password . ' --host=db host', $output);
    }
}
