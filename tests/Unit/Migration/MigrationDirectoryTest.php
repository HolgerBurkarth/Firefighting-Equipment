<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Migration;

use FirefightingEquipment\Migration\Checksum;
use FirefightingEquipment\Migration\MigrationDirectory;
use FirefightingEquipment\Migration\MigrationException;
use FirefightingEquipment\Migration\MigrationFile;
use FirefightingEquipment\Migration\SqlSplitter;
use FirefightingEquipment\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationDirectory::class)]
#[UsesClass(Checksum::class)]
#[UsesClass(MigrationException::class)]
#[UsesClass(MigrationFile::class)]
#[UsesClass(SqlSplitter::class)]
final class MigrationDirectoryTest extends TestCase
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

    public function testEmptyDirectoryGivesEmptyList(): void
    {
        self::assertSame([], $this->load());
    }

    public function testFilesAreLoadedInOrder(): void
    {
        $this->dir->write('0002_second.sql', "SELECT 2;\n");
        $this->dir->write('0001_first.sql', "SELECT 1;\nSELECT 11;\n");

        $files = $this->load();

        self::assertCount(2, $files);
        self::assertSame(1, $files[0]->version);
        self::assertSame('0001_first.sql', $files[0]->filename);
        self::assertSame(['SELECT 1', 'SELECT 11'], $files[0]->statements);
        self::assertSame(Checksum::of("SELECT 1;\nSELECT 11;\n"), $files[0]->checksum);
        self::assertSame(2, $files[1]->version);
        self::assertSame('0002_second.sql', $files[1]->filename);
    }

    public function testDotFilesAreIgnored(): void
    {
        $this->dir->write('.gitkeep', '');
        $this->dir->write('.hidden.sql', 'kein SQL');
        $this->dir->write('0001_first.sql', 'SELECT 1;');

        self::assertCount(1, $this->load());
    }

    public function testCrlfFileHasSameChecksumAsLfFile(): void
    {
        $this->dir->write('0001_first.sql', "SELECT 1;\r\nSELECT 2;\r\n");

        $file = $this->load()[0];

        self::assertSame(Checksum::of("SELECT 1;\nSELECT 2;\n"), $file->checksum);
        self::assertSame(['SELECT 1', 'SELECT 2'], $file->statements);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'zu kurze Nummer' => ['1_first.sql'];
        yield 'zu lange Nummer' => ['00001_first.sql'];
        yield 'Großbuchstaben' => ['0001_First.sql'];
        yield 'Endung groß' => ['0001_first.SQL'];
        yield 'Bindestrich' => ['0001_first-table.sql'];
        yield 'ohne Beschreibung' => ['0001.sql'];
        yield 'falsche Endung' => ['0001_first.txt'];
        yield 'Sicherungskopie' => ['0001_first.sql~'];
        yield 'README' => ['README.md'];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidFilenameThrows(string $name): void
    {
        $this->dir->write($name, 'SELECT 1;');

        $this->expectExceptionMessage('Ungültiger Dateiname im Migrationsordner: ' . $name);
        $this->load();
    }

    public function testSubdirectoryWithValidNameThrows(): void
    {
        mkdir($this->dir->file('0001_first.sql'));

        $this->expectExceptionMessage('ist keine Datei');
        $this->load();
    }

    public function testDuplicateNumberThrows(): void
    {
        $this->dir->write('0001_first.sql', 'SELECT 1;');
        $this->dir->write('0001_other.sql', 'SELECT 1;');

        $this->expectExceptionMessage('Nummer 0001 ist doppelt vergeben: 0001_first.sql und 0001_other.sql.');
        $this->load();
    }

    public function testGapThrows(): void
    {
        $this->dir->write('0001_first.sql', 'SELECT 1;');
        $this->dir->write('0003_third.sql', 'SELECT 3;');

        $this->expectExceptionMessage('Lücke in der Nummerierung: 0002 fehlt (nächste Datei ist 0003_third.sql).');
        $this->load();
    }

    public function testNumberingMustStartAt0001(): void
    {
        $this->dir->write('0002_second.sql', 'SELECT 2;');

        $this->expectExceptionMessage('Die Nummerierung muss mit 0001 beginnen, erste Datei ist 0002_second.sql.');
        $this->load();
    }

    public function testZeroIsNotAllowed(): void
    {
        $this->dir->write('0000_zero.sql', 'SELECT 0;');
        $this->dir->write('0001_first.sql', 'SELECT 1;');

        $this->expectExceptionMessage('Die Nummerierung muss mit 0001 beginnen, erste Datei ist 0000_zero.sql.');
        $this->load();
    }

    public function testFileWithoutStatementThrows(): void
    {
        $this->dir->write('0001_first.sql', "-- nur ein Kommentar\n");

        $this->expectExceptionMessage('Migration 0001_first.sql enthält keine SQL-Anweisung.');
        $this->load();
    }

    public function testSplitterErrorNamesFile(): void
    {
        $this->dir->write('0001_first.sql', "SELECT 'offen;");

        $this->expectExceptionMessage('Migration 0001_first.sql: Nicht abgeschlossene Zeichenkette');
        $this->load();
    }

    public function testBomThrows(): void
    {
        $this->dir->write('0001_first.sql', "\u{FEFF}SELECT 1;");

        $this->expectExceptionMessage('Migration 0001_first.sql beginnt mit einem BOM');
        $this->load();
    }

    public function testNonUtf8Throws(): void
    {
        $this->dir->write('0001_first.sql', "SELECT 'Gr\xF6\xDFe';");

        $this->expectExceptionMessage('Migration 0001_first.sql ist nicht in UTF-8 gespeichert.');
        $this->load();
    }

    public function testMissingDirectoryThrows(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Migrationsordner nicht gefunden');

        new MigrationDirectory($this->dir->file('gibt-es-nicht'))->load();
    }

    public function testProjectMigrationsDirectoryIsValid(): void
    {
        $files = new MigrationDirectory(__DIR__ . '/../../../migrations')->load();

        self::assertSame(array_keys(array_fill(1, count($files), true)), array_map(static fn (MigrationFile $f): int => $f->version, $files));
    }

    /**
     * @return list<MigrationFile>
     */
    private function load(): array
    {
        return new MigrationDirectory($this->dir->path)->load();
    }
}
