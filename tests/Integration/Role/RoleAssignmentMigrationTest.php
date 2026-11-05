<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Role;

use FirefightingEquipment\Migration\SqlSplitter;
use FirefightingEquipment\Role\RoleAssignmentCheck;
use FirefightingEquipment\Tests\Integration\User\AuthSchemaTestCase;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Migrationen 0036 (Konten auf Standard-Rollen) und 0037 (CHECK chk_fe_users_role_id) auf dem Stand
 * davor: 0036 ändert genau die Konten, die der Probelauf zählt, und danach läuft 0037 durch. Fehlt
 * einer Stadt eine Standard-Rolle, scheitert 0037 und ändert nichts. DomainTestCase führt beide auf
 * leeren Tabellen aus; hier laufen sie noch einmal (0037 nach Entfernen des CHECK, der am Ende in
 * jedem Fall wieder besteht).
 */
#[CoversNothing]
final class RoleAssignmentMigrationTest extends AuthSchemaTestCase
{
    use LegacyRoleAccounts;

    private const string ASSIGN = self::MIGRATIONS_DIR . '/0036_update_fe_users_role_id.sql';
    private const string CHECK = self::MIGRATIONS_DIR . '/0037_add_fe_users_role_id_check.sql';

    protected function tearDown(): void
    {
        if ($this->hasConnection() && !$this->checkExists()) {
            // Zeilen, die den CHECK verletzen, dürfen nicht stehen bleiben.
            TestDatabase::clearDomainTables($this->pdo());
            $this->runFile(self::CHECK);
        }
        parent::tearDown();
    }

    public function testAssignsExactlyTheCountedAccountsAndCheckPasses(): void
    {
        $this->legacyAccounts();
        $before = $this->pdo()->query('SELECT id, tenant_id, role, status, email, updated_at FROM fe_users ORDER BY id')->fetchAll();
        $report = new RoleAssignmentCheck($this->pdo())->run();

        $changed = $this->runFile(self::ASSIGN);

        self::assertSame($report->totalToAssign(), $changed);
        self::assertSame(8, $changed);
        self::assertSame(
            $before,
            $this->pdo()->query('SELECT id, tenant_id, role, status, email, updated_at FROM fe_users ORDER BY id')->fetchAll(),
            'Nur role_id ändert sich, auch updated_at bleibt.',
        );
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM fe_users WHERE (tenant_id IS NULL) <> (role_id IS NULL)'));
        self::assertSame(0, $this->scalar(
            'SELECT COUNT(*) FROM fe_users u JOIN fe_roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id'
            . ' WHERE r.system_key <> u.role',
        ));

        $after = new RoleAssignmentCheck($this->pdo())->run();
        self::assertSame([], $after->toAssign);
        self::assertSame(9, $after->alreadyAssigned);
        self::assertTrue($after->isConsistentAfterMigration());

        self::assertSame(0, $this->runFile(self::ASSIGN), 'Wiederholbar: ein zweiter Lauf ändert nichts.');

        $this->dropCheck();
        $this->runFile(self::CHECK);
        self::assertTrue($this->checkExists());
    }

    public function testCheckFailsAndChangesNothingWhenATenantLacksStandardRoles(): void
    {
        $this->legacyAccounts();
        $bare = $this->createTenant('geisingen', false);
        $this->withoutChecks(fn () => $this->createUser($bare, ['role_id' => null]));

        $report = new RoleAssignmentCheck($this->pdo())->run();
        self::assertSame(1, $report->totalUnassigned());
        self::assertSame(8, $this->runFile(self::ASSIGN));

        $this->dropCheck();
        try {
            $this->runFile(self::CHECK);
            self::fail('0037 hätte scheitern müssen.');
        } catch (\PDOException $e) {
            self::assertSame(self::ER_CONSTRAINT_FAILED, $e->errorInfo[1] ?? null, $e->getMessage());
        }
        self::assertFalse($this->checkExists(), 'Ein gescheitertes ALTER legt den CHECK nicht an.');
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM fe_users WHERE tenant_id IS NOT NULL AND role_id IS NULL'));
    }

    public function testCheckRejectsAccountsWithoutRoleAndSuperAdminsWithRole(): void
    {
        $tenantId = $this->createTenant();
        $roleId = $this->systemRoleId($tenantId, 'user');

        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->createUser($tenantId, ['role_id' => null]));
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->createUser(null, ['role' => 'superadmin', 'role_id' => $roleId]));
        $userId = $this->createUser($tenantId);
        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->pdo()->exec('UPDATE fe_users SET role_id = NULL WHERE id = ' . $userId),
        );
    }

    /**
     * @return int geänderte Zeilen der letzten Anweisung
     */
    private function runFile(string $path): int
    {
        $content = file_get_contents($path);
        self::assertIsString($content);
        $changed = 0;
        foreach (SqlSplitter::split($content) as $statement) {
            $changed = (int) $this->pdo()->exec($statement);
        }

        return $changed;
    }

    private function dropCheck(): void
    {
        $this->pdo()->exec('ALTER TABLE fe_users DROP CONSTRAINT chk_fe_users_role_id');
    }

    private function checkExists(): bool
    {
        return $this->scalar(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'fe_users' AND CONSTRAINT_NAME = 'chk_fe_users_role_id'",
        ) === 1;
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo()->query($sql)->fetchColumn();
    }
}
