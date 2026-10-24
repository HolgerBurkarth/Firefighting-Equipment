<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Scripts;

use FirefightingEquipment\Tests\Support\Bash;
use FirefightingEquipment\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Startet scripts/prune-deploy-backups.sh mit bash (übersprungen, wenn keine bash vorhanden ist).
 */
#[CoversNothing]
final class PruneDeployBackupsTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../scripts/prune-deploy-backups.sh';
    private const int BASE_TIME = 1_790_000_000;

    private TempDirectory $dir;

    protected function setUp(): void
    {
        if (Bash::path() === null) {
            self::markTestSkipped('bash nicht gefunden.');
        }
        $this->dir = new TempDirectory();
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->dir->remove();
        }
    }

    public function testMixedNamesAreOrderedByModificationTime(): void
    {
        // Lage auf dem Server: Ältere Sicherungen tragen Ortszeit (UTC+2) ohne Z im Namen und
        // sortieren nach Namen hinter der gerade erzeugten UTC-Sicherung.
        $this->write('ff-deploy-20261020-114500-81a0d4c.tar.gz', 100);
        $this->write('ff-deploy-20261021-114500-4016b52.tar.gz', 200);
        $this->write('ff-deploy-20261022-114500-df3a466.tar.gz', 300);
        $this->write('ff-deploy-20261023-114500-df3a466.tar.gz', 400);
        $this->write('ff-deploy-20261024-114500-b33e689.tar.gz', 500);
        $this->write('ff-deploy-20261024-095032Z-b33e689.tar.gz', 600);

        [$exitCode, $stdout, $stderr] = $this->prune('5', 'ff-deploy-20261024-095032Z-b33e689.tar.gz');

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame(
            'Lösche alte Sicherung: ' . Bash::toPath($this->dir->path) . "/ff-deploy-20261020-114500-81a0d4c.tar.gz\n",
            $stdout,
        );
        self::assertSame([
            'ff-deploy-20261021-114500-4016b52.tar.gz',
            'ff-deploy-20261022-114500-df3a466.tar.gz',
            'ff-deploy-20261023-114500-df3a466.tar.gz',
            'ff-deploy-20261024-095032Z-b33e689.tar.gz',
            'ff-deploy-20261024-114500-b33e689.tar.gz',
        ], $this->files());
    }

    public function testNewerUtcNameWithOlderLocalNamesDeletesOldestByTimeFirst(): void
    {
        $this->write('ff-deploy-20261024-120000Z-aaaaaaa.tar.gz', 300);
        $this->write('ff-deploy-20261024-130000-bbbbbbb.tar.gz', 100);
        $this->write('ff-deploy-20261024-110000Z-ccccccc.tar.gz', 400);
        $this->write('ff-deploy-20261024-140000-ddddddd.tar.gz', 200);

        [$exitCode, $stdout] = $this->prune('2');

        self::assertSame(0, $exitCode);
        $dir = Bash::toPath($this->dir->path);
        self::assertSame(
            "Lösche alte Sicherung: $dir/ff-deploy-20261024-130000-bbbbbbb.tar.gz\n"
            . "Lösche alte Sicherung: $dir/ff-deploy-20261024-140000-ddddddd.tar.gz\n",
            $stdout,
        );
        self::assertSame(
            ['ff-deploy-20261024-110000Z-ccccccc.tar.gz', 'ff-deploy-20261024-120000Z-aaaaaaa.tar.gz'],
            $this->files(),
        );
    }

    public function testSameModificationTimeIsOrderedByName(): void
    {
        $this->write('ff-deploy-20261024-090002Z-aaaaaaa.tar.gz', 100);
        $this->write('ff-deploy-20261024-090000Z-aaaaaaa.tar.gz', 100);
        $this->write('ff-deploy-20261024-090001Z-aaaaaaa.tar.gz', 100);

        [$exitCode] = $this->prune('1');

        self::assertSame(0, $exitCode);
        self::assertSame(['ff-deploy-20261024-090002Z-aaaaaaa.tar.gz'], $this->files());
    }

    public function testCurrentBackupIsNeverDeleted(): void
    {
        // Die gerade erzeugte Sicherung hat das älteste Änderungsdatum (verstellte Uhr) und
        // wird als vollständiger Pfad übergeben, wie in deploy.sh.
        $current = 'ff-deploy-20000101-000000Z-b33e689.tar.gz';
        $this->write($current, 10);
        $this->write('ff-deploy-20261024-100000Z-aaaaaaa.tar.gz', 100);
        $this->write('ff-deploy-20261024-110000Z-bbbbbbb.tar.gz', 200);

        [$exitCode] = $this->prune('2', Bash::toPath($this->dir->file($current)));

        self::assertSame(0, $exitCode);
        self::assertSame([$current, 'ff-deploy-20261024-110000Z-bbbbbbb.tar.gz'], $this->files());

        [$exitCode] = $this->prune('1', $current);

        self::assertSame(0, $exitCode);
        self::assertSame([$current], $this->files());
    }

    public function testForeignFilesAreNeverTouched(): void
    {
        $foreign = [
            '.htaccess',
            'ff-db-20200101-000000Z.sql.gz',
            'ff-deploy-20200101-000000Z-abc1234.tar.gz.part',
            'ff-deploy-20200101-000000Z-ABC1234.tar.gz',
            'ff-deploy-20200101-0000Z-abc1234.tar.gz',
            'notizen.txt',
        ];
        foreach ($foreign as $name) {
            $this->write($name, 1);
        }
        mkdir($this->dir->file('ff-deploy-20200101-000000Z-def5678.tar.gz'));
        $this->write('ff-deploy-20261024-100000Z-aaaaaaa.tar.gz', 100);
        $this->write('ff-deploy-20261024-110000Z-bbbbbbb.tar.gz', 200);

        [$exitCode] = $this->prune('1');

        self::assertSame(0, $exitCode);
        foreach ($foreign as $name) {
            self::assertFileExists($this->dir->file($name));
        }
        self::assertDirectoryExists($this->dir->file('ff-deploy-20200101-000000Z-def5678.tar.gz'));
        self::assertFileDoesNotExist($this->dir->file('ff-deploy-20261024-100000Z-aaaaaaa.tar.gz'));
        self::assertFileExists($this->dir->file('ff-deploy-20261024-110000Z-bbbbbbb.tar.gz'));
    }

    public function testNothingIsDeletedWhenAtMostKeep(): void
    {
        $this->write('ff-deploy-20261024-100000Z-aaaaaaa.tar.gz', 100);
        $this->write('ff-deploy-20261024-110000Z-bbbbbbb.tar.gz', 200);

        [$exitCode, $stdout] = $this->prune('2', 'ff-deploy-20261024-110000Z-bbbbbbb.tar.gz');

        self::assertSame(0, $exitCode);
        self::assertSame('', $stdout);
        self::assertCount(2, $this->files());
    }

    public function testEmptyDirectory(): void
    {
        [$exitCode, $stdout, $stderr] = $this->prune('5');

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame('', $stdout);
    }

    public function testInvalidArgumentsGiveExitCode2(): void
    {
        foreach ([['0'], ['abc'], ['-1'], []] as $args) {
            [$exitCode, , $stderr] = Bash::run(self::SCRIPT, [Bash::toPath($this->dir->path), ...$args]);

            self::assertSame(2, $exitCode, implode(' ', $args));
            self::assertStringContainsString('Aufruf:', $stderr);
        }
    }

    public function testMissingDirectoryGivesExitCode1(): void
    {
        [$exitCode, , $stderr] = Bash::run(self::SCRIPT, [Bash::toPath($this->dir->file('gibt-es-nicht')), '5']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('FEHLER:', $stderr);
    }

    public function testDeployScriptsHaveValidSyntax(): void
    {
        foreach (['deploy.sh', 'prune-deploy-backups.sh'] as $script) {
            [$exitCode, , $stderr] = Bash::run('-n', [Bash::toPath(dirname(self::SCRIPT) . '/' . $script)]);

            self::assertSame(0, $exitCode, $script . ': ' . $stderr);
        }
    }

    /**
     * @return array{int, string, string}
     */
    private function prune(string $keep, ?string $current = null): array
    {
        return Bash::run(self::SCRIPT, [
            Bash::toPath($this->dir->path),
            $keep,
            ...($current === null ? [] : [$current]),
        ]);
    }

    private function write(string $name, int $offset): void
    {
        $this->dir->write($name, 'x');
        touch($this->dir->file($name), self::BASE_TIME + $offset);
    }

    /**
     * @return list<string> Sicherungen ff-deploy-*.tar.gz, nach Namen sortiert
     */
    private function files(): array
    {
        $files = array_values(array_filter(
            scandir($this->dir->path) ?: [],
            fn (string $entry): bool => str_starts_with($entry, 'ff-deploy-')
                && str_ends_with($entry, '.tar.gz')
                && is_file($this->dir->file($entry)),
        ));
        sort($files, SORT_STRING);

        return $files;
    }
}
