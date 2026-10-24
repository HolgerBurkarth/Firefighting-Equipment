<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration;

use FirefightingEquipment\Migration\Migrator;
use FirefightingEquipment\Tests\Support\TestDatabase;

/**
 * Basis für fachliche Integrationstests mit echtem Schema. Führt die Migrationen aus migrations/
 * einmal pro Testlauf in der Testdatenbank aus (Protokoll in fe_schema_migrations der
 * Testdatenbank) und leert vor jedem Test die fachlichen Tabellen.
 */
abstract class DomainTestCase extends IntegrationTestCase
{
    public const string MIGRATIONS_DIR = __DIR__ . '/../../migrations';

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$migrated) {
            TestDatabase::assertConnectedToTestDatabase($this->pdo());
            new Migrator($this->pdo(), self::MIGRATIONS_DIR)->run();
            self::$migrated = true;
        }

        TestDatabase::truncateDomainTables($this->pdo());
    }
}
