<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Role;

use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\PermissionRepository;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tests\Integration\User\AuthSchemaTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(PermissionRepository::class)]
#[UsesClass(Permissions::class)]
final class PermissionRepositoryTest extends AuthSchemaTestCase
{
    private Tenant $tenant;
    private PermissionRepository $permissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = new TenantRepository($this->pdo())->findById($this->createTenant()) ?? self::fail('Stadt fehlt.');
        $this->permissions = new PermissionRepository($this->pdo());
    }

    public function testAdminRoleHasAllRights(): void
    {
        $admin = $this->createUser($this->tenant->id, ['role' => 'admin']);

        self::assertTrue($this->permissions->forUser($this->tenant, $admin)->isAll());
    }

    public function testUserRoleHasItsRows(): void
    {
        $user = $this->createUser($this->tenant->id);

        $permissions = $this->permissions->forUser($this->tenant, $user);

        self::assertFalse($permissions->isAll());
        self::assertSame(AreaLevel::Read, $permissions->level(Area::Search));
        self::assertSame(AreaLevel::Read, $permissions->level(Area::Due));
        self::assertNull($permissions->level(Area::Devices));
        self::assertTrue($permissions->can(Action::BookMove));
        self::assertFalse($permissions->can(Action::MailLog));
    }

    public function testOtherRoleAndChangesApplyImmediately(): void
    {
        $roleId = $this->insert('fe_roles', ['tenant_id' => $this->tenant->id, 'name' => 'Gerätewart']);
        $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $this->tenant->id, 'area' => 'devices', 'level' => 'write']);
        $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $this->tenant->id, 'action' => 'device.edit']);
        $user = $this->createUser($this->tenant->id, ['role_id' => $roleId]);

        self::assertTrue($this->permissions->forUser($this->tenant, $user)->can(Action::DeviceEdit));

        $this->pdo()->exec('DELETE FROM fe_role_actions WHERE role_id = ' . $roleId);
        self::assertFalse($this->permissions->forUser($this->tenant, $user)->can(Action::DeviceEdit));
        self::assertTrue($this->permissions->forUser($this->tenant, $user)->allows(Area::Devices, AreaLevel::Write));
    }

    public function testCarriesRoleRevisionAndFilter(): void
    {
        $admin = $this->createUser($this->tenant->id, ['role' => 'admin']);
        $adminRole = $this->systemRoleId($this->tenant->id, 'admin');
        $roleId = $this->insert('fe_roles', ['tenant_id' => $this->tenant->id, 'name' => 'Wache 2', 'filter_locations' => 1]);
        $user = $this->createUser($this->tenant->id, ['role_id' => $roleId]);

        self::assertSame($adminRole . ':1', $this->permissions->forUser($this->tenant, $admin)->revision());
        self::assertFalse($this->permissions->forUser($this->tenant, $admin)->isFiltered());
        self::assertSame($roleId . ':1', $this->permissions->forUser($this->tenant, $user)->revision());
        self::assertTrue($this->permissions->forUser($this->tenant, $user)->isFiltered());

        $this->pdo()->exec('UPDATE fe_roles SET version = 4, filter_locations = 0, filter_device_types = 1 WHERE id = ' . $roleId);
        self::assertSame($roleId . ':4', $this->permissions->forUser($this->tenant, $user)->revision());
        self::assertTrue($this->permissions->forUser($this->tenant, $user)->isFiltered());
        self::assertSame($roleId . ':4', $this->permissions->forTenantUser($this->tenant->id, $user)->revision());
        self::assertNull($this->permissions->forUser($this->tenant, 999_999)->revision());
    }

    /**
     * Die SQL-Bedingung für viele Konten (Empfänger der Log-Mails) entscheidet wie Permissions::can:
     * je Aktion an der Rolle Admin, an der Standard-Rolle Benutzer und an eigenen Rollen mit und
     * ohne Aktion bzw. Bereich.
     */
    public function testGrantsActionSqlAgreesWithPermissions(): void
    {
        $accounts = [
            $this->createUser($this->tenant->id, ['role' => 'admin']),
            $this->createUser($this->tenant->id),
            $this->createSuperadmin(),
        ];
        $variants = [
            [[], Action::cases()],
            [['devices' => 'read', 'search' => 'read'], Action::cases()],
            [['devices' => 'write', 'search' => 'read'], Action::cases()],
            [['devices' => 'write', 'search' => 'write', 'due' => 'write'], [Action::DeviceEdit, Action::MailLog]],
            [['search' => 'read'], []],
        ];
        foreach ($variants as $i => [$areas, $actions]) {
            $roleId = $this->insert('fe_roles', ['tenant_id' => $this->tenant->id, 'name' => 'Rolle ' . $i]);
            foreach ($areas as $area => $level) {
                $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $this->tenant->id, 'area' => $area, 'level' => $level]);
            }
            foreach ($actions as $action) {
                $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $this->tenant->id, 'action' => $action->value]);
            }
            $accounts[] = $this->createUser($this->tenant->id, ['role_id' => $roleId]);
        }

        foreach (Action::cases() as $action) {
            [$condition, $params] = PermissionRepository::grantsActionSql('u', $action);
            $stmt = $this->pdo()->prepare('SELECT u.id FROM fe_users u WHERE ' . $condition . ' ORDER BY u.id');
            $stmt->execute($params);
            $expected = array_values(array_filter(
                $accounts,
                fn (int $id): bool => $this->permissions->forUser($this->tenant, $id)->can($action),
            ));

            self::assertSame($expected, array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), $action->value);
        }
    }

    public function testGrantsActionSqlRejectsInvalidAlias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PermissionRepository::grantsActionSql('u; DROP', Action::MailLog);
    }

    public function testAccountOfOtherTenantOrSuperAdminHasNoRights(): void
    {
        $other = $this->createUser($this->createTenant('tuttlingen'), ['role' => 'admin']);
        $superAdmin = $this->createSuperadmin();

        self::assertFalse($this->permissions->forUser($this->tenant, $other)->isAll());
        self::assertNull($this->permissions->forUser($this->tenant, $other)->level(Area::Search));
        self::assertNull($this->permissions->forUser($this->tenant, $superAdmin)->level(Area::Search));
        self::assertNull($this->permissions->forUser($this->tenant, 999_999)->level(Area::Search));
    }
}
