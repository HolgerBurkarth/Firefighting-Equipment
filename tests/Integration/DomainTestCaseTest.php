<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration;

use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Prüft die Testbasis: echte Migrationen ausgeführt, Leeren nur der fachlichen Tabellen.
 */
#[CoversNothing]
final class DomainTestCaseTest extends DomainTestCase
{
    public function testAllMigrationsAreApplied(): void
    {
        $files = array_values(array_filter(
            scandir(self::MIGRATIONS_DIR) ?: [],
            static fn (string $entry): bool => str_ends_with($entry, '.sql'),
        ));
        $applied = $this->pdo()->query('SELECT filename FROM fe_schema_migrations ORDER BY version')
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertNotSame([], $files);
        self::assertSame($files, $applied);
    }

    public function testClearEmptiesDomainTablesOnly(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO fe_tenants (slug, name) VALUES ('immendingen', 'Feuerwehr Immendingen')");
        $pdo->exec('CREATE TABLE fe_test_keep (id INT PRIMARY KEY) ENGINE=InnoDB');

        try {
            $pdo->exec('INSERT INTO fe_test_keep (id) VALUES (1)');
            $migrations = $pdo->query('SELECT COUNT(*) FROM fe_schema_migrations')->fetchColumn();

            TestDatabase::clearDomainTables($pdo);

            self::assertSame(0, $pdo->query('SELECT COUNT(*) FROM fe_tenants')->fetchColumn());
            self::assertSame(1, $pdo->query('SELECT COUNT(*) FROM fe_test_keep')->fetchColumn());
            self::assertSame($migrations, $pdo->query('SELECT COUNT(*) FROM fe_schema_migrations')->fetchColumn());
        } finally {
            TestDatabase::dropTestTables($pdo);
        }
    }

    public function testTablesAreEmptyAtStartOfTest(): void
    {
        self::assertSame(0, $this->pdo()->query('SELECT COUNT(*) FROM fe_tenants')->fetchColumn());
    }
}
