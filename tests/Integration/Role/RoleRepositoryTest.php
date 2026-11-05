<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Role;

use FirefightingEquipment\Role\DefaultRoles;
use FirefightingEquipment\Role\Role;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\Role\SystemRole;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Integration\Device\DeviceSchemaTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(RoleRepository::class)]
#[CoversClass(Role::class)]
#[CoversClass(SystemRole::class)]
#[CoversClass(DefaultRoles::class)]
#[UsesClass(TenantRepository::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantSlug::class)]
final class RoleRepositoryTest extends DeviceSchemaTestCase
{
    private RoleRepository $roles;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roles = new RoleRepository($this->pdo());
        $this->tenant = $this->createTenantWithRoles('immendingen');
    }

    public function testNewTenantGetsAdminAndUserRole(): void
    {
        $list = $this->roles->listByTenant($this->tenant);

        self::assertCount(2, $list);
        [$admin, $user] = $list;

        self::assertSame(SystemRole::Admin, $admin->systemRole);
        self::assertSame(DefaultRoles::ADMIN_NAME, $admin->name);
        self::assertSame(DefaultRoles::ADMIN_DESCRIPTION, $admin->description);
        self::assertSame([], $admin->areas, 'Admin hat alle Rechte ohne eigene Zeilen.');
        self::assertSame([], $admin->actions);

        self::assertSame(SystemRole::User, $user->systemRole);
        self::assertSame(DefaultRoles::USER_NAME, $user->name);
        self::assertSame(DefaultRoles::USER_DESCRIPTION, $user->description);
        self::assertSame(['due' => 'read', 'search' => 'read'], $user->areas);
        self::assertSame(['book.move', 'book.status'], $user->actions);

        foreach ($list as $role) {
            self::assertSame($this->tenant->id, $role->tenantId);
            self::assertFalse($role->restrictSingleUse);
            self::assertFalse($role->filterLocations);
            self::assertFalse($role->filterDeviceTypes);
            self::assertSame([], $role->locationIds);
            self::assertSame([], $role->deviceTypeIds);
            self::assertSame(1, $role->version);
            self::assertSame('UTC', $role->createdAt->getTimezone()->getName());
            self::assertEquals($role->createdAt, $role->updatedAt);
        }
    }

    public function testDefaultAreasAndActionsAreSorted(): void
    {
        $areas = DefaultRoles::USER_AREAS;
        ksort($areas, SORT_STRING);
        $actions = DefaultRoles::USER_ACTIONS;
        sort($actions, SORT_STRING);

        self::assertSame($areas, DefaultRoles::USER_AREAS);
        self::assertSame($actions, DefaultRoles::USER_ACTIONS);
    }

    public function testListOrdersStandardRolesFirstThenByName(): void
    {
        $this->insertRole('Zugführer');
        $this->insertRole('atemschutz');
        $this->insertRole('Gerätewart');

        self::assertSame(
            ['Admin', 'Benutzer', 'atemschutz', 'Gerätewart', 'Zugführer'],
            array_map(static fn (Role $role): string => $role->name, $this->roles->listByTenant($this->tenant)),
        );
    }

    public function testFindAndFindSystemRoleOnlyInOwnTenant(): void
    {
        $other = $this->createTenantWithRoles('tuttlingen');
        $otherUser = $this->roles->findSystemRole($other, SystemRole::User);
        self::assertInstanceOf(Role::class, $otherUser);

        self::assertNull($this->roles->find($this->tenant, $otherUser->id));
        self::assertEquals($otherUser, $this->roles->find($other, $otherUser->id));

        $admin = $this->roles->findSystemRole($this->tenant, SystemRole::Admin);
        self::assertInstanceOf(Role::class, $admin);
        self::assertSame($this->tenant->id, $admin->tenantId);
        self::assertNotSame($admin->id, $this->roles->findSystemRole($other, SystemRole::Admin)?->id);
        self::assertNull($this->roles->find($this->tenant, 999_999));
    }

    public function testRoleWithFiltersAndRightsIsReadCompletely(): void
    {
        $roleId = $this->insertRole('Atemschutz', [
            'description' => 'Nur Atemschutz',
            'restrict_single_use' => 1,
            'filter_locations' => 1,
            'filter_device_types' => 1,
            'version' => 4,
        ]);
        $vehicle = $this->createLocation($this->tenant->id, ['name' => 'HLF 20']);
        $room = $this->createLocation($this->tenant->id, ['name' => 'Lager', 'kind' => 'room']);
        $mask = $this->createDeviceType($this->tenant->id, ['name' => 'Maske']);
        foreach ([$room, $vehicle] as $locationId) {
            $this->insert('fe_role_locations', ['role_id' => $roleId, 'tenant_id' => $this->tenant->id, 'location_id' => $locationId]);
        }
        $this->insert('fe_role_device_types', ['role_id' => $roleId, 'tenant_id' => $this->tenant->id, 'device_type_id' => $mask]);
        foreach (['search' => 'read', 'devices' => 'write', 'due' => 'read'] as $area => $level) {
            $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $this->tenant->id, 'area' => $area, 'level' => $level]);
        }
        foreach (['device.edit', 'book.move'] as $action) {
            $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $this->tenant->id, 'action' => $action]);
        }

        $role = $this->roles->find($this->tenant, $roleId);

        self::assertInstanceOf(Role::class, $role);
        self::assertNull($role->systemRole);
        self::assertSame('Nur Atemschutz', $role->description);
        self::assertTrue($role->restrictSingleUse);
        self::assertTrue($role->filterLocations);
        self::assertTrue($role->filterDeviceTypes);
        self::assertSame(['devices' => 'write', 'due' => 'read', 'search' => 'read'], $role->areas);
        self::assertSame(['book.move', 'device.edit'], $role->actions);
        self::assertSame([min($vehicle, $room), max($vehicle, $room)], $role->locationIds);
        self::assertSame([$mask], $role->deviceTypeIds);
        self::assertSame(4, $role->version);
    }

    public function testCreateDefaultsRunsInsideOpenTransaction(): void
    {
        $tenantId = $this->createTenant('tuttlingen');
        $tenant = new TenantRepository($this->pdo())->findById($tenantId);
        self::assertInstanceOf(Tenant::class, $tenant);

        $this->pdo()->beginTransaction();
        $this->roles->createDefaults($tenant);
        self::assertTrue($this->pdo()->inTransaction(), 'Die Transaktion des Aufrufers bleibt offen.');
        $this->pdo()->rollBack();

        self::assertSame([], $this->roles->listByTenant($tenant));
    }

    public function testCreateDefaultsTwiceFailsWithoutPartialRows(): void
    {
        $before = $this->countRows('fe_roles');

        try {
            $this->roles->createDefaults($this->tenant);
            self::fail('PDOException erwartet.');
        } catch (\PDOException $e) {
            self::assertSame(self::ER_DUP_ENTRY, $e->errorInfo[1] ?? null);
        }

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame($before, $this->countRows('fe_roles'));
    }

    public function testTenantRollbackRemovesRoles(): void
    {
        $this->pdo()->beginTransaction();
        $tenant = new TenantRepository($this->pdo())->create(TenantSlug::fromString('tuttlingen'), TenantName::fromString('Feuerwehr Tuttlingen'));
        self::assertCount(2, $this->roles->listByTenant($tenant));
        $this->pdo()->rollBack();

        self::assertSame([], $this->roles->listByTenant($tenant));
    }

    private function createTenantWithRoles(string $slug): Tenant
    {
        return new TenantRepository($this->pdo())->create(TenantSlug::fromString($slug), TenantName::fromString('Feuerwehr ' . $slug));
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    private function insertRole(string $name, array $overrides = []): int
    {
        return $this->insert('fe_roles', array_merge(['tenant_id' => $this->tenant->id, 'name' => $name], $overrides));
    }
}
