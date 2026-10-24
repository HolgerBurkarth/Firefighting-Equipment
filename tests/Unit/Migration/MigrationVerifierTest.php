<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Migration;

use FirefightingEquipment\Migration\AppliedMigration;
use FirefightingEquipment\Migration\MigrationException;
use FirefightingEquipment\Migration\MigrationFile;
use FirefightingEquipment\Migration\MigrationVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationVerifier::class)]
#[UsesClass(AppliedMigration::class)]
#[UsesClass(MigrationException::class)]
#[UsesClass(MigrationFile::class)]
final class MigrationVerifierTest extends TestCase
{
    public function testNothingAppliedMeansAllPending(): void
    {
        $files = [self::file(1), self::file(2)];

        self::assertSame($files, MigrationVerifier::pending($files, []));
    }

    public function testAppliedAreNotPending(): void
    {
        $files = [self::file(1), self::file(2), self::file(3)];

        $pending = MigrationVerifier::pending($files, [self::applied(1), self::applied(2)]);

        self::assertSame([$files[2]], $pending);
    }

    public function testAllAppliedMeansNothingPending(): void
    {
        self::assertSame([], MigrationVerifier::pending([self::file(1)], [self::applied(1)]));
    }

    public function testAppliedOrderInLogDoesNotMatter(): void
    {
        $files = [self::file(1), self::file(2), self::file(3)];

        self::assertSame([$files[2]], MigrationVerifier::pending($files, [self::applied(2), self::applied(1)]));
    }

    public function testChangedChecksumThrows(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage(
            'Die bereits ausgeführte Migration 0001_m.sql wurde geändert (Prüfsumme im Protokoll '
            . str_repeat('0', 64) . ', Datei ' . str_repeat('a', 64) . ')',
        );

        MigrationVerifier::pending(
            [self::file(1, str_repeat('a', 64))],
            [self::applied(1, str_repeat('0', 64))],
        );
    }

    public function testMissingFileThrows(): void
    {
        $this->expectExceptionMessage('Die bereits ausgeführte Migration 0002_m.sql fehlt im Migrationsordner.');

        MigrationVerifier::pending([self::file(1)], [self::applied(1), self::applied(2)]);
    }

    public function testRenamedFileThrows(): void
    {
        $this->expectExceptionMessage('Migration 0001 wurde als 0001_m.sql ausgeführt, die Datei heißt jetzt 0001_neu.sql.');

        MigrationVerifier::pending(
            [new MigrationFile(1, '0001_neu.sql', self::checksum(1), ['SELECT 1'])],
            [self::applied(1)],
        );
    }

    public function testGapInLogThrows(): void
    {
        $this->expectExceptionMessage('Das Protokoll ist lückenhaft: Version 0002 fehlt, obwohl 0003_m.sql ausgeführt wurde.');

        MigrationVerifier::pending([self::file(1), self::file(2), self::file(3)], [self::applied(1), self::applied(3)]);
    }

    public function testLogNotStartingAtOneThrows(): void
    {
        $this->expectExceptionMessage('Version 0001 fehlt, obwohl 0002_m.sql ausgeführt wurde.');

        MigrationVerifier::pending([self::file(1), self::file(2)], [self::applied(2)]);
    }

    private static function checksum(int $version): string
    {
        return hash('sha256', (string) $version);
    }

    private static function file(int $version, ?string $checksum = null): MigrationFile
    {
        return new MigrationFile(
            $version,
            sprintf('%04d_m.sql', $version),
            $checksum ?? self::checksum($version),
            ['SELECT ' . $version],
        );
    }

    private static function applied(int $version, ?string $checksum = null): AppliedMigration
    {
        return new AppliedMigration(
            $version,
            sprintf('%04d_m.sql', $version),
            $checksum ?? self::checksum($version),
            '2026-10-24 12:00:00.000',
            1,
        );
    }
}
