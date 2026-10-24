<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Backup;

use FirefightingEquipment\Backup\BackupFileName;
use FirefightingEquipment\Backup\BackupRotation;
use FirefightingEquipment\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackupRotation::class)]
#[UsesClass(BackupFileName::class)]
final class BackupRotationTest extends TestCase
{
    private const int BASE_TIME = 1_790_000_000;
    private const int DAY = 86_400;

    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = new TempDirectory();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testKeepsTenNewestAndDeletesOlder(): void
    {
        $names = [];
        for ($day = 1; $day <= 12; $day++) {
            $names[] = sprintf('ff-db-202610%02d-120000Z-vor-migration-abc%d.sql.gz', $day, $day);
        }
        // Absichtlich nicht in zeitlicher Reihenfolge anlegen.
        foreach (array_reverse($names, true) as $i => $name) {
            $this->write($name, self::DAY * ($i + 1));
        }

        $deleted = BackupRotation::prune($this->dir->path);

        self::assertSame([$this->dir->file($names[0]), $this->dir->file($names[1])], $deleted);
        self::assertSame(array_slice($names, 2), $this->backupFiles());
    }

    public function testOrderIsByModificationTimeNotByName(): void
    {
        // Wie auf dem Server: alte Namen in Ortszeit (UTC+2) sortieren hinter neueren UTC-Namen.
        $this->write('ff-db-20261024-115000Z-alt.sql.gz', 1000);
        $this->write('ff-db-20261024-113000Z-aelter.sql.gz', 900);
        $this->write('ff-db-20261024-100000Z-neu.sql.gz', 2000);
        $this->write('ff-db-20261024-095032Z-neuer.sql.gz', 3000);

        $deleted = BackupRotation::prune($this->dir->path, 2);

        self::assertSame([
            $this->dir->file('ff-db-20261024-113000Z-aelter.sql.gz'),
            $this->dir->file('ff-db-20261024-115000Z-alt.sql.gz'),
        ], $deleted);
        self::assertSame(
            ['ff-db-20261024-095032Z-neuer.sql.gz', 'ff-db-20261024-100000Z-neu.sql.gz'],
            $this->backupFiles(),
        );
    }

    public function testSameModificationTimeIsOrderedByName(): void
    {
        $this->write('ff-db-20261024-090002Z.sql.gz', 1000);
        $this->write('ff-db-20261024-090000Z.sql.gz', 1000);
        $this->write('ff-db-20261024-090001Z.sql.gz', 1000);

        $deleted = BackupRotation::prune($this->dir->path, 1);

        self::assertSame([
            $this->dir->file('ff-db-20261024-090000Z.sql.gz'),
            $this->dir->file('ff-db-20261024-090001Z.sql.gz'),
        ], $deleted);
    }

    public function testKeepFileIsNeverDeletedAndCountsAsKept(): void
    {
        // Die gerade erzeugte Sicherung hat hier (z. B. wegen einer verstellten Uhr) das älteste
        // Änderungsdatum und den kleinsten Namen; sie bleibt trotzdem.
        $current = 'ff-db-20000101-000000Z-vor-migration-abc1234.sql.gz';
        $this->write($current, 10);
        $this->write('ff-db-20261024-110000Z.sql.gz', 1000);
        $this->write('ff-db-20261024-120000Z.sql.gz', 2000);
        $this->write('ff-db-20261024-130000Z.sql.gz', 3000);

        $deleted = BackupRotation::prune($this->dir->path, 2, $current);

        self::assertSame([
            $this->dir->file('ff-db-20261024-110000Z.sql.gz'),
            $this->dir->file('ff-db-20261024-120000Z.sql.gz'),
        ], $deleted);
        self::assertSame([$current, 'ff-db-20261024-130000Z.sql.gz'], $this->backupFiles());
    }

    public function testKeepFileAloneIsNeverDeletedWithKeepOne(): void
    {
        $current = 'ff-db-20000101-000000Z.sql.gz';
        $this->write($current, 10);
        $this->write('ff-db-20261024-110000Z.sql.gz', 1000);

        BackupRotation::prune($this->dir->path, 1, $current);

        self::assertSame([$current], $this->backupFiles());
    }

    public function testUnknownKeepFileDoesNotReduceKept(): void
    {
        $this->write('ff-db-20261024-110000Z.sql.gz', 1000);
        $this->write('ff-db-20261024-120000Z.sql.gz', 2000);

        self::assertSame([], BackupRotation::prune($this->dir->path, 2, 'ff-db-20261024-130000Z.sql.gz'));
        self::assertCount(2, $this->backupFiles());
    }

    public function testNothingIsDeletedWhenAtMostKeep(): void
    {
        for ($i = 0; $i < BackupRotation::KEEP; $i++) {
            $this->dir->write(sprintf('ff-db-20261024-0900%02dZ.sql.gz', $i), 'x');
        }

        self::assertSame([], BackupRotation::prune($this->dir->path));
        self::assertCount(BackupRotation::KEEP, $this->backupFiles());
    }

    public function testForeignFilesAreNeverTouched(): void
    {
        $foreign = [
            '.htaccess',
            'ff-deploy-20200101-000000-abc1234.tar.gz',
            'ff-deploy-20200101-000000Z-abc1234.tar.gz',
            'ff-db-20200101-000000Z.sql.gz.part',
            'ff-db-20200101-000000.sql.gz',
            '.ff-db-0123456789abcdef.cnf',
            'notizen.txt',
        ];
        foreach ($foreign as $name) {
            $this->dir->write($name, 'x');
        }
        // Ein Ordner mit passendem Namen ist keine Sicherung.
        mkdir($this->dir->file('ff-db-20200101-000000Z.sql.gz'));
        for ($i = 0; $i < 3; $i++) {
            $this->dir->write(sprintf('ff-db-20261024-0900%02dZ.sql.gz', $i), 'x');
        }

        $deleted = BackupRotation::prune($this->dir->path, 1);

        self::assertCount(2, $deleted);
        foreach ($foreign as $name) {
            self::assertFileExists($this->dir->file($name));
        }
        self::assertDirectoryExists($this->dir->file('ff-db-20200101-000000Z.sql.gz'));
        self::assertFileExists($this->dir->file('ff-db-20261024-090002Z.sql.gz'));
    }

    public function testKeepMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BackupRotation::prune($this->dir->path, 0);
    }

    /**
     * Legt eine Sicherung mit festem Änderungsdatum (Sekunden nach BASE_TIME) an.
     */
    private function write(string $name, int $offset): void
    {
        $this->dir->write($name, 'x');
        touch($this->dir->file($name), self::BASE_TIME + $offset);
    }

    /**
     * @return list<string>
     */
    private function backupFiles(): array
    {
        $files = array_values(array_filter(
            scandir($this->dir->path) ?: [],
            static fn (string $entry): bool => BackupFileName::matches($entry),
        ));
        sort($files);

        return $files;
    }
}
