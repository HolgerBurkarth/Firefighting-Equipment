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

    public function testClearEmptiesDependentTablesInOneCommit(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO fe_tenants (slug, name) VALUES ('immendingen', 'Feuerwehr Immendingen')");
        $tenantId = (int) $pdo->lastInsertId();
        $pdo->exec(sprintf("INSERT INTO fe_device_types (tenant_id, name) VALUES (%d, 'Atemschutzmaske')", $tenantId));
        $commitsBefore = self::sessionCommits($pdo);

        TestDatabase::clearDomainTables($pdo);

        // Genau ein ausdrückliches COMMIT (Com_commit); einzeln per Autocommit gelöscht wären es 0.
        self::assertSame(1, self::sessionCommits($pdo) - $commitsBefore);
        self::assertFalse($pdo->inTransaction());
        self::assertSame(1, $pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn());

        // Für andere Verbindungen sichtbar, also wirklich committet.
        $other = TestDatabase::connect();
        self::assertSame(0, $other->query('SELECT COUNT(*) FROM fe_tenants')->fetchColumn());
        self::assertSame(0, $other->query('SELECT COUNT(*) FROM fe_device_types')->fetchColumn());
    }

    public function testClearRefusesOpenTransactionAndChangesNothing(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO fe_tenants (slug, name) VALUES ('immendingen', 'Feuerwehr Immendingen')");
        $pdo->beginTransaction();

        try {
            TestDatabase::clearDomainTables($pdo);
            self::fail('Eine offene Transaktion wurde nicht abgelehnt.');
        } catch (\LogicException) {
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }

        self::assertSame(1, $pdo->query('SELECT COUNT(*) FROM fe_tenants')->fetchColumn());
    }

    public function testTablesAreEmptyAtStartOfTest(): void
    {
        self::assertSame(0, $this->pdo()->query('SELECT COUNT(*) FROM fe_tenants')->fetchColumn());
    }

    private static function sessionCommits(\PDO $pdo): int
    {
        return (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com\\_commit'")->fetch(\PDO::FETCH_NUM)[1];
    }
}
