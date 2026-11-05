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

    /** Test, dessen Zeilen noch in der Datenbank stehen (geprüft vor dem Leeren). */
    private static ?string $previousTest = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$migrated) {
            TestDatabase::assertConnectedToTestDatabase($this->pdo());
            new Migrator($this->pdo(), self::MIGRATIONS_DIR)->run();
            self::$migrated = true;
        }

        if (self::$previousTest !== null) {
            $this->assertAccountRolesFitAccountKinds(self::$previousTest);
        }
        TestDatabase::clearDomainTables($this->pdo());
        self::$previousTest = static::class . '::' . $this->name();
    }

    /**
     * Regel aus dem Plan Phase 1 (nur im Code gesichert, kein CHECK möglich): fe_users.role = 'admin'
     * genau dann, wenn role_id die Rolle Admin der Stadt ist. Geprüft über alle Zeilen, die der
     * vorige Test hinterlassen hat, also nach jedem Schreibweg jedes Tests.
     */
    private function assertAccountRolesFitAccountKinds(string $test): void
    {
        $mismatched = $this->pdo()->query(
            'SELECT GROUP_CONCAT(u.id ORDER BY u.id) FROM fe_users u JOIN fe_roles r ON r.id = u.role_id'
            . " WHERE (u.role = 'admin') <> (r.system_key <=> 'admin')",
        )->fetchColumn();

        self::assertNull($mismatched, sprintf('Konten mit Rolle, die nicht zur Kontoart passt (nach %s): %s', $test, (string) $mismatched));
    }
}
