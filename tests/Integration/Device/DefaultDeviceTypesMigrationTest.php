<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Device\DefaultDeviceTypes;
use FirefightingEquipment\Migration\SqlSplitter;
use FirefightingEquipment\Tests\Integration\DomainTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Migration 0012 ergänzt die Start-Gerätetypen für bestehende Städte. DomainTestCase führt sie auf
 * leeren Tabellen aus; hier läuft sie (nur DML) noch einmal mit vorhandenen Städten.
 */
#[CoversNothing]
final class DefaultDeviceTypesMigrationTest extends DomainTestCase
{
    private const string MIGRATION = self::MIGRATIONS_DIR . '/0012_insert_fe_device_types_defaults.sql';

    public function testEveryTenantGetsTheDefaultTypes(): void
    {
        $this->pdo()->exec(
            "INSERT INTO fe_tenants (slug, name, active) VALUES ('immendingen', 'Feuerwehr Immendingen', 1),"
            . " ('tuttlingen', 'Feuerwehr Tuttlingen', 0)",
        );

        $this->runMigration();

        $expected = DefaultDeviceTypes::NAMES;
        sort($expected);
        foreach (['immendingen', 'tuttlingen'] as $slug) {
            $stmt = $this->pdo()->prepare(
                'SELECT d.name, d.active, d.created_at = d.updated_at AS same_time,'
                . ' TIMESTAMPDIFF(SECOND, d.created_at, UTC_TIMESTAMP()) AS age'
                . ' FROM fe_device_types d JOIN fe_tenants t ON t.id = d.tenant_id'
                . ' WHERE t.slug = ? ORDER BY d.name COLLATE utf8mb4_bin',
            );
            $stmt->execute([$slug]);
            $rows = $stmt->fetchAll();

            self::assertSame($expected, array_column($rows, 'name'), $slug);
            foreach ($rows as $row) {
                self::assertSame(1, $row['active']);
                self::assertSame(1, $row['same_time']);
                self::assertLessThan(60, abs($row['age']));
            }
        }
    }

    public function testWithoutTenantsNothingIsInserted(): void
    {
        $this->runMigration();

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_device_types')->fetchColumn());
    }

    private function runMigration(): void
    {
        $content = file_get_contents(self::MIGRATION);
        self::assertIsString($content);
        foreach (SqlSplitter::split($content) as $statement) {
            $this->pdo()->exec($statement);
        }
    }
}
