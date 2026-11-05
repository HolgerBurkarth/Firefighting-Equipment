<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Role;

use FirefightingEquipment\Migration\SqlSplitter;
use FirefightingEquipment\Role\DefaultRoles;
use FirefightingEquipment\Role\Role;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\Role\SystemRole;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Integration\DomainTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Migration 0034 ergänzt die Standard-Rollen für bestehende Städte. DomainTestCase führt sie auf
 * leeren Tabellen aus; hier läuft sie (nur DML) noch einmal mit vorhandenen Städten. Das Ergebnis
 * muss dem Anlegen einer neuen Stadt entsprechen (DefaultRoles).
 */
#[CoversNothing]
final class DefaultRolesMigrationTest extends DomainTestCase
{
    private const string MIGRATION = self::MIGRATIONS_DIR . '/0034_insert_fe_roles_defaults.sql';

    public function testEveryTenantGetsTheDefaultRoles(): void
    {
        $this->pdo()->exec(
            "INSERT INTO fe_tenants (slug, name, active) VALUES ('immendingen', 'Feuerwehr Immendingen', 1),"
            . " ('tuttlingen', 'Feuerwehr Tuttlingen', 0)",
        );

        $this->runMigration();

        $tenants = new TenantRepository($this->pdo());
        $roles = new RoleRepository($this->pdo());
        foreach (['immendingen', 'tuttlingen'] as $slug) {
            $tenant = $tenants->findBySlug(TenantSlug::fromString($slug));
            self::assertInstanceOf(Tenant::class, $tenant);
            $list = $roles->listByTenant($tenant);

            self::assertSame(self::expectedDefaults(), array_map(self::summary(...), $list), $slug);
            foreach ($list as $role) {
                self::assertEquals($role->createdAt, $role->updatedAt);
                self::assertLessThan(60, abs($role->createdAt->getTimestamp() - time()));
            }
        }
    }

    public function testMigrationMatchesNewTenant(): void
    {
        $this->pdo()->exec("INSERT INTO fe_tenants (slug, name, active) VALUES ('immendingen', 'Feuerwehr Immendingen', 1)");
        $this->runMigration();
        $created = new TenantRepository($this->pdo())->create(TenantSlug::fromString('tuttlingen'), TenantName::fromString('Feuerwehr Tuttlingen'));

        $roles = new RoleRepository($this->pdo());
        $migrated = new TenantRepository($this->pdo())->findBySlug(TenantSlug::fromString('immendingen'));
        self::assertInstanceOf(Tenant::class, $migrated);

        self::assertSame(
            array_map(self::summary(...), $roles->listByTenant($migrated)),
            array_map(self::summary(...), $roles->listByTenant($created)),
        );
    }

    public function testWithoutTenantsNothingIsInserted(): void
    {
        $this->runMigration();

        foreach (['fe_roles', 'fe_role_areas', 'fe_role_actions'] as $table) {
            self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), $table);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function expectedDefaults(): array
    {
        $defaults = [
            'restrict_single_use' => false,
            'filter_locations' => false,
            'filter_device_types' => false,
            'location_ids' => [],
            'device_type_ids' => [],
            'version' => 1,
        ];

        return [
            ['system' => SystemRole::Admin, 'name' => DefaultRoles::ADMIN_NAME, 'description' => DefaultRoles::ADMIN_DESCRIPTION,
                'areas' => [], 'actions' => []] + $defaults,
            ['system' => SystemRole::User, 'name' => DefaultRoles::USER_NAME, 'description' => DefaultRoles::USER_DESCRIPTION,
                'areas' => DefaultRoles::USER_AREAS, 'actions' => DefaultRoles::USER_ACTIONS] + $defaults,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(Role $role): array
    {
        return [
            'system' => $role->systemRole,
            'name' => $role->name,
            'description' => $role->description,
            'areas' => $role->areas,
            'actions' => $role->actions,
            'restrict_single_use' => $role->restrictSingleUse,
            'filter_locations' => $role->filterLocations,
            'filter_device_types' => $role->filterDeviceTypes,
            'location_ids' => $role->locationIds,
            'device_type_ids' => $role->deviceTypeIds,
            'version' => $role->version,
        ];
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
