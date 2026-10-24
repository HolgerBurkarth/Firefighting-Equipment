<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Migration;

use FirefightingEquipment\Tests\Integration\IntegrationTestCase;
use FirefightingEquipment\Tests\Support\TestDatabase;

/**
 * Basis für Migrationstests. Verwendet die Protokolltabelle fe_test_schema_migrations und
 * räumt vor und nach jedem Test ausschließlich Tabellen mit Präfix fe_test_ auf.
 */
abstract class MigrationTestCase extends IntegrationTestCase
{
    protected const string TABLE = 'fe_test_schema_migrations';
    protected const string FIXTURES = __DIR__ . '/../../Fixtures/migrations/';

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabase::dropTestTables($this->pdo());
    }

    protected function tearDown(): void
    {
        if ($this->hasConnection()) {
            TestDatabase::dropTestTables($this->pdo());
        }
        parent::tearDown();
    }

    protected function tableExists(string $table): bool
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function logRows(): array
    {
        return $this->pdo()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY version')->fetchAll();
    }
}
