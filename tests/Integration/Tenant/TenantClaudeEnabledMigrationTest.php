<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Tenant;

use FirefightingEquipment\Migration\SqlSplitter;
use FirefightingEquipment\Tests\Integration\User\AuthSchemaTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Migration 0066 (fe_tenants.claude_enabled, T17): Standard aus, nur 0/1, und das UPDATE der
 * Migration schaltet genau die Test-Stadt ein (Freigabe Holger 16.11.2026). DomainTestCase führt die
 * Migration auf leeren Tabellen aus; hier läuft ihr UPDATE noch einmal auf Städten.
 */
#[CoversNothing]
final class TenantClaudeEnabledMigrationTest extends AuthSchemaTestCase
{
    private const string MIGRATION = self::MIGRATIONS_DIR . '/0066_add_fe_tenants_claude_enabled.sql';

    public function testUpdateEnablesOnlyTheTestCity(): void
    {
        foreach (['immendingen', 'test', 'test-2', 'tuttlingen'] as $slug) {
            $this->createTenant($slug);
        }
        $before = $this->pdo()->query('SELECT id, slug, name, active, accent_color, updated_at FROM fe_tenants ORDER BY id')->fetchAll();
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM fe_tenants WHERE claude_enabled <> 0'), 'Standard aus');

        $statements = SqlSplitter::split((string) file_get_contents(self::MIGRATION));
        self::assertCount(2, $statements, 'eine DDL-Anweisung und genau ein UPDATE');
        self::assertStringContainsString('ALTER TABLE fe_tenants', $statements[0]);
        self::assertStringStartsWith('UPDATE fe_tenants', $statements[1]);
        $changed = (int) $this->pdo()->exec($statements[1]);

        self::assertSame(1, $changed);
        self::assertSame(['test'], $this->pdo()->query('SELECT slug FROM fe_tenants WHERE claude_enabled = 1')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame($before, $this->pdo()->query('SELECT id, slug, name, active, accent_color, updated_at FROM fe_tenants ORDER BY id')->fetchAll());
    }

    public function testDatabaseAcceptsOnlyZeroAndOne(): void
    {
        $tenant = $this->createTenant();
        $this->pdo()->prepare('UPDATE fe_tenants SET claude_enabled = 1 WHERE id = ?')->execute([$tenant]);

        $this->expectException(\PDOException::class);
        $this->pdo()->prepare('UPDATE fe_tenants SET claude_enabled = 2 WHERE id = ?')->execute([$tenant]);
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo()->query($sql)->fetchColumn();
    }
}
