<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Backup;

use FirefightingEquipment\Backup\BackupFileName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackupFileName::class)]
final class BackupFileNameTest extends TestCase
{
    public function testNameWithoutSuffix(): void
    {
        $time = new \DateTimeImmutable('2026-10-24 09:30:00', new \DateTimeZone('UTC'));

        self::assertSame('ff-db-20261024-093000Z.sql.gz', BackupFileName::create($time));
    }

    public function testNameWithSuffix(): void
    {
        $time = new \DateTimeImmutable('2026-10-24 09:30:00', new \DateTimeZone('UTC'));

        self::assertSame(
            'ff-db-20261024-093000Z-vor-migration-81a0d4c.sql.gz',
            BackupFileName::create($time, 'vor-migration-81a0d4c'),
        );
    }

    public function testTimeIsConvertedToUtc(): void
    {
        $time = new \DateTimeImmutable('2026-10-24 11:30:00', new \DateTimeZone('Europe/Berlin'));

        self::assertSame('ff-db-20261024-093000Z.sql.gz', BackupFileName::create($time));
    }

    public function testCreatedNamesMatch(): void
    {
        $time = new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'));

        self::assertTrue(BackupFileName::matches(BackupFileName::create($time)));
        self::assertTrue(BackupFileName::matches(BackupFileName::create($time, 'a-1')));
    }

    public function testNamesSortByTime(): void
    {
        $names = [
            BackupFileName::create(new \DateTimeImmutable('2026-10-24 10:00:00Z'), 'a'),
            BackupFileName::create(new \DateTimeImmutable('2026-10-24 09:59:59Z'), 'zzz'),
            BackupFileName::create(new \DateTimeImmutable('2025-12-31 23:00:00Z')),
        ];
        $sorted = $names;
        sort($sorted, SORT_STRING);

        self::assertSame([$names[2], $names[1], $names[0]], $sorted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSuffixes(): iterable
    {
        yield 'leer' => [''];
        yield 'Großbuchstaben' => ['Vor-Migration'];
        yield 'Bindestrich am Anfang' => ['-abc'];
        yield 'Bindestrich am Ende' => ['abc-'];
        yield 'doppelter Bindestrich' => ['a--b'];
        yield 'Punkt' => ['a.b'];
        yield 'Schrägstrich' => ['../x'];
        yield 'Leerzeichen' => ['a b'];
        yield 'Unterstrich' => ['a_b'];
        yield 'Umlaut' => ['grün'];
        yield 'zu lang' => [str_repeat('a', BackupFileName::SUFFIX_MAX_LENGTH + 1)];
    }

    #[DataProvider('invalidSuffixes')]
    public function testInvalidSuffixIsRejected(string $suffix): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BackupFileName::create(new \DateTimeImmutable(), $suffix);
    }

    public function testMaximumSuffixLengthIsAllowed(): void
    {
        $suffix = str_repeat('a', BackupFileName::SUFFIX_MAX_LENGTH);

        self::assertStringContainsString($suffix, BackupFileName::create(new \DateTimeImmutable(), $suffix));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignNames(): iterable
    {
        yield 'Deploy-Sicherung' => ['ff-deploy-20261024-093000Z-81a0d4c.tar.gz'];
        yield 'ohne Z' => ['ff-db-20261024-093000.sql.gz'];
        yield 'unfertig' => ['ff-db-20261024-093000Z.sql.gz.part'];
        yield 'Zugangsdatei' => ['.ff-db-0123456789abcdef.cnf'];
        yield 'nicht komprimiert' => ['ff-db-20261024-093000Z.sql'];
        yield 'Zeilenumbruch am Ende' => ["ff-db-20261024-093000Z.sql.gz\n"];
        yield 'anderer Präfix' => ['x-ff-db-20261024-093000Z.sql.gz'];
        yield 'htaccess' => ['.htaccess'];
    }

    #[DataProvider('foreignNames')]
    public function testForeignNamesDoNotMatch(string $name): void
    {
        self::assertFalse(BackupFileName::matches($name));
    }
}
