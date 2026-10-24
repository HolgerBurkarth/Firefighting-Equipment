<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Migration;

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
use FirefightingEquipment\Tests\Support\TempDirectory;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(Migrator::class)]
#[CoversClass(MigrationLog::class)]
#[CoversClass(MigrationLock::class)]
#[UsesClass(AppliedMigration::class)]
#[UsesClass(Checksum::class)]
#[UsesClass(DatabaseError::class)]
#[UsesClass(MigrationDirectory::class)]
#[UsesClass(MigrationException::class)]
#[UsesClass(MigrationFile::class)]
#[UsesClass(MigrationStatus::class)]
#[UsesClass(MigrationVerifier::class)]
#[UsesClass(SqlSplitter::class)]
final class MigratorTest extends MigrationTestCase
{
    private ?TempDirectory $temp = null;

    protected function tearDown(): void
    {
        $this->temp?->remove();
        parent::tearDown();
    }

    public function testStatusBeforeRunIsReadOnly(): void
    {
        $status = $this->migrator('valid')->status();

        self::assertSame(self::TABLE, $status->table);
        self::assertFalse($status->tableExists);
        self::assertSame([], $status->applied);
        self::assertSame(
            ['0001_create_fe_test_items.sql', '0002_create_fe_test_places.sql'],
            array_map(static fn (MigrationFile $f): string => $f->filename, $status->pending),
        );
        self::assertFalse($this->tableExists(self::TABLE));
    }

    public function testRunAppliesAllMigrationsAndRecordsThem(): void
    {
        $applied = [];
        $count = $this->migrator('valid')->run(static function (MigrationFile $file, int $ms) use (&$applied): void {
            $applied[] = $file->filename;
        });

        self::assertSame(2, $count);
        self::assertSame(['0001_create_fe_test_items.sql', '0002_create_fe_test_places.sql'], $applied);
        self::assertTrue($this->tableExists('fe_test_places'));
        self::assertSame(
            ['Atemschutzmaske; Größe M', 'Wärmebildkamera'],
            $this->pdo()->query('SELECT name FROM fe_test_items ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN),
        );

        $rows = $this->logRows();
        self::assertCount(2, $rows);
        self::assertSame(1, $rows[0]['version']);
        self::assertSame('0001_create_fe_test_items.sql', $rows[0]['filename']);
        self::assertSame(
            Checksum::of((string) file_get_contents(self::FIXTURES . 'valid/0001_create_fe_test_items.sql')),
            $rows[0]['checksum'],
        );
        self::assertGreaterThanOrEqual(0, $rows[0]['duration_ms']);
        self::assertSame(2, $rows[1]['version']);
    }

    public function testAppliedAtIsUtc(): void
    {
        $this->migrator('valid')->run();

        $appliedAt = $this->migrator('valid')->status()->applied[0]->appliedAt;
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/D', $appliedAt);

        $diff = new \DateTimeImmutable($appliedAt, new \DateTimeZone('UTC'))->getTimestamp() - time();
        self::assertLessThan(120, abs($diff));
    }

    public function testLogTableUsesUtf8mb4UnicodeCi(): void
    {
        $this->migrator('valid')->run();

        $stmt = $this->pdo()->prepare(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        );
        $stmt->execute([self::TABLE]);
        self::assertSame('utf8mb4_unicode_ci', $stmt->fetchColumn());
    }

    public function testSecondRunDoesNothing(): void
    {
        $this->migrator('valid')->run();
        $before = $this->logRows();

        self::assertSame(0, $this->migrator('valid')->run());
        self::assertSame($before, $this->logRows());

        $status = $this->migrator('valid')->status();
        self::assertTrue($status->tableExists);
        self::assertCount(2, $status->applied);
        self::assertSame([], $status->pending);
    }

    public function testNewMigrationIsAppliedLater(): void
    {
        $this->temp = TempDirectory::copyOf(self::FIXTURES . 'valid');
        unlink($this->temp->file('0002_create_fe_test_places.sql'));
        $this->migratorFor($this->temp->path)->run();

        copy(self::FIXTURES . 'valid/0002_create_fe_test_places.sql', $this->temp->file('0002_create_fe_test_places.sql'));

        self::assertSame(1, $this->migratorFor($this->temp->path)->run());
        self::assertCount(2, $this->logRows());
    }

    public function testFailingMigrationAbortsWithFilenameAndIsNotRecorded(): void
    {
        try {
            $this->migrator('broken')->run();
            self::fail('MigrationException erwartet.');
        } catch (MigrationException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('Migration 0002_insert_duplicate.sql fehlgeschlagen bei Anweisung 2 von 2', $message);
            self::assertStringContainsString('SQLSTATE 23000, Fehler 1062', $message);
            self::assertStringContainsString('Anweisung 1 dieser Migration wurde bereits ausgeführt', $message);
            self::assertStringContainsString('Anweisung: INSERT INTO fe_test_broken (id) VALUES (1), (1)', $message);
            self::assertStringContainsString('Nachfolgende Migrationen wurden nicht ausgeführt.', $message);
            self::assertNull($e->getPrevious());
        }

        self::assertSame([1], array_column($this->logRows(), 'version'));
        // DDL wird von MariaDB nicht zurückgenommen.
        self::assertTrue($this->tableExists('fe_test_broken'));
        self::assertFalse($this->tableExists('fe_test_never'));
    }

    public function testChangedChecksumAbortsWithoutExecuting(): void
    {
        $this->temp = TempDirectory::copyOf(self::FIXTURES . 'valid');
        $this->migratorFor($this->temp->path)->run();

        file_put_contents($this->temp->file('0001_create_fe_test_items.sql'), "\n-- geändert\n", FILE_APPEND);
        $this->temp->write('0003_create_fe_test_never.sql', 'CREATE TABLE fe_test_never (id INT);');

        try {
            $this->migratorFor($this->temp->path)->run();
            self::fail('MigrationException erwartet.');
        } catch (MigrationException $e) {
            self::assertStringContainsString('Die bereits ausgeführte Migration 0001_create_fe_test_items.sql wurde geändert', $e->getMessage());
        }
        self::assertFalse($this->tableExists('fe_test_never'));

        $this->expectExceptionMessage('wurde geändert');
        $this->migratorFor($this->temp->path)->status();
    }

    public function testCrlfCopyHasSameChecksum(): void
    {
        $this->temp = TempDirectory::copyOf(self::FIXTURES . 'valid');
        $this->migratorFor($this->temp->path)->run();

        $file = $this->temp->file('0001_create_fe_test_items.sql');
        file_put_contents($file, str_replace("\n", "\r\n", (string) file_get_contents($file)));

        self::assertSame([], $this->migratorFor($this->temp->path)->status()->pending);
    }

    public function testMissingFileAborts(): void
    {
        $this->temp = TempDirectory::copyOf(self::FIXTURES . 'valid');
        $this->migratorFor($this->temp->path)->run();

        unlink($this->temp->file('0002_create_fe_test_places.sql'));

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Die bereits ausgeführte Migration 0002_create_fe_test_places.sql fehlt im Migrationsordner.');
        $this->migratorFor($this->temp->path)->run();
    }

    public function testRunAbortsWhileAnotherRunHoldsTheLock(): void
    {
        $database = (string) $this->pdo()->query('SELECT DATABASE()')->fetchColumn();
        $other = TestDatabase::connect();
        $lock = new MigrationLock($other, MigrationLock::nameFor($database, self::TABLE));
        $lock->acquire();

        try {
            $this->migrator('valid')->run();
            self::fail('MigrationException erwartet.');
        } catch (MigrationException $e) {
            self::assertSame('Es läuft bereits eine Migration (Sperre belegt). Abbruch.', $e->getMessage());
        } finally {
            $lock->release();
        }

        self::assertFalse($this->tableExists(self::TABLE));
        // Nach Freigabe der Sperre klappt der Lauf.
        self::assertSame(2, $this->migrator('valid')->run());
    }

    public function testLockIsReleasedAfterFailure(): void
    {
        try {
            $this->migrator('broken')->run();
        } catch (MigrationException) {
        }

        $database = (string) $this->pdo()->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $this->pdo()->prepare('SELECT IS_FREE_LOCK(?)');
        $stmt->execute([MigrationLock::nameFor($database, self::TABLE)]);
        self::assertSame(1, $stmt->fetchColumn());
    }

    private function migrator(string $fixture): Migrator
    {
        return $this->migratorFor(self::FIXTURES . $fixture);
    }

    private function migratorFor(string $directory): Migrator
    {
        return new Migrator($this->pdo(), $directory, self::TABLE);
    }
}
