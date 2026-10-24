<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Backup;

use FirefightingEquipment\Backup\BackupDirectory;
use FirefightingEquipment\Backup\BackupException;
use FirefightingEquipment\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackupDirectory::class)]
final class BackupDirectoryTest extends TestCase
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

    public function testDirectoryWithHtaccessIsUsable(): void
    {
        $this->dir->write('.htaccess', 'Require all denied');

        self::assertSame(realpath($this->dir->path), BackupDirectory::assertUsable($this->dir->path));
    }

    public function testMissingDirectoryIsRejected(): void
    {
        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Sicherungsordner existiert nicht');

        BackupDirectory::assertUsable($this->dir->file('gibt-es-nicht'));
    }

    public function testFileInsteadOfDirectoryIsRejected(): void
    {
        $this->dir->write('datei', 'x');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Sicherungsordner existiert nicht');

        BackupDirectory::assertUsable($this->dir->file('datei'));
    }

    public function testDirectoryWithoutHtaccessIsRejected(): void
    {
        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('fehlt die .htaccess');

        BackupDirectory::assertUsable($this->dir->path);
    }

    public function testHtaccessDirectoryIsNotEnough(): void
    {
        mkdir($this->dir->file('.htaccess'));

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('fehlt die .htaccess');

        BackupDirectory::assertUsable($this->dir->path);
    }
}
