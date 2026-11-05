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
