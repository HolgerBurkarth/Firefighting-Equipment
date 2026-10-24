<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Migration;

use FirefightingEquipment\Migration\MigrationLock;
use FirefightingEquipment\Migration\MigrationLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationLog::class)]
#[CoversClass(MigrationLock::class)]
final class MigrationLogTest extends TestCase
{
    public function testValidTableNamesAreAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        MigrationLog::assertValidTableName(MigrationLog::DEFAULT_TABLE);
        MigrationLog::assertValidTableName('fe_test_schema_migrations');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTableNames(): iterable
    {
        yield 'ohne Präfix' => ['schema_migrations'];
        yield 'nur Präfix' => ['fe_'];
        yield 'Großbuchstaben' => ['fe_Schema'];
        yield 'Backtick' => ['fe_x`; DROP TABLE fe_y'];
        yield 'Punkt' => ['fe_db.table'];
        yield 'zu lang' => ['fe_' . str_repeat('a', 62)];
    }

    #[DataProvider('invalidTableNames')]
    public function testInvalidTableNamesAreRejected(string $table): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MigrationLog::assertValidTableName($table);
    }

    public function testLockNameIsShortAndDependsOnDatabaseAndTable(): void
    {
        $name = MigrationLock::nameFor(str_repeat('d', 64), str_repeat('t', 64));

        self::assertLessThanOrEqual(64, strlen($name));
        self::assertStringStartsWith('fe_migrate_', $name);
        self::assertNotSame(MigrationLock::nameFor('a_test', 'fe_x'), MigrationLock::nameFor('b_test', 'fe_x'));
        self::assertNotSame(MigrationLock::nameFor('a_test', 'fe_x'), MigrationLock::nameFor('a_test', 'fe_y'));
    }
}
