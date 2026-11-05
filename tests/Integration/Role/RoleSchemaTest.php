<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Role;

use FirefightingEquipment\Tests\Integration\Device\DeviceSchemaTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_roles, fe_role_areas, fe_role_actions, fe_role_locations, fe_role_device_types und
 * fe_users.role_id (Migrationen 0029–0033, 0035): Format, Eindeutigkeit, Bezug zur selben Stadt.
 */
#[CoversNothing]
final class RoleSchemaTest extends DeviceSchemaTestCase
{
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant();
    }

    public function testValidRoleIsAcceptedWithDefaults(): void
    {
        $id = $this->createRole($this->tenantId, ['name' => 'Gerätewart']);

        $row = $this->pdo()->query(
            'SELECT system_key, description, restrict_single_use, filter_locations, filter_device_types, version, updated_by'
            . ' FROM fe_roles WHERE id = ' . $id,
        )->fetch();

        self::assertSame([
            'system_key' => null,
            'description' => null,
            'restrict_single_use' => 0,
            'filter_locations' => 0,
            'filter_device_types' => 0,
            'version' => 1,
            'updated_by' => null,
        ], $row);
    }

    public function testSameNameInOtherTenantIsAccepted(): void
    {
        $this->createRole($this->tenantId, ['name' => 'Gerätewart', 'system_key' => 'user']);
        $this->createRole($this->createTenant('tuttlingen'), ['name' => 'Gerätewart', 'system_key' => 'user']);

        self::assertSame(2, $this->countRows('fe_roles'));
    }

    public function testDatabaseRejectsDuplicateNameIgnoringCase(): void
    {
        $this->createRole($this->tenantId, ['name' => 'Gerätewart']);

        $this->assertRejected(self::ER_DUP_ENTRY, fn () => $this->createRole($this->tenantId, ['name' => 'GERÄTEWART']));
    }

    public function testEachSystemKeyOnlyOncePerTenant(): void
    {
        $this->createRole($this->tenantId, ['name' => 'Admin', 'system_key' => 'admin']);
        $this->createRole($this->tenantId, ['name' => 'Eins']);
        $this->createRole($this->tenantId, ['name' => 'Zwei']);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->createRole($this->tenantId, ['name' => 'Admin 2', 'system_key' => 'admin']),
        );
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidRoles(): iterable
    {
        yield 'unbekannter system_key' => [['system_key' => 'superadmin']];
        yield 'system_key groß' => [['system_key' => 'Admin']];
        yield 'leerer Name' => [['name' => '   ']];
        yield 'leere Beschreibung' => [['description' => ' ']];
        yield 'restrict_single_use 2' => [['restrict_single_use' => 2]];
        yield 'filter_locations 2' => [['filter_locations' => 2]];
        yield 'filter_device_types 2' => [['filter_device_types' => 2]];
        yield 'version 0' => [['version' => 0]];
        yield 'Admin mit Standortfilter' => [['system_key' => 'admin', 'filter_locations' => 1]];
        yield 'Admin mit Typfilter' => [['system_key' => 'admin', 'filter_device_types' => 1]];
        yield 'Admin mit Einschränkung' => [['system_key' => 'admin', 'restrict_single_use' => 1]];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidRoles')]
    public function testDatabaseRejectsInvalidRole(array $overrides): void
    {
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->createRole($this->tenantId, $overrides));
        self::assertSame(0, $this->countRows('fe_roles'));
    }

    public function testOtherRolesMayHaveFiltersAndRestriction(): void
    {
        $this->createRole($this->tenantId, ['system_key' => 'user', 'filter_locations' => 1, 'filter_device_types' => 1, 'restrict_single_use' => 1]);
        $this->createRole($this->tenantId, ['name' => 'Träger', 'filter_locations' => 1]);

        self::assertSame(2, $this->countRows('fe_roles'));
    }

    public function testDatabaseRejectsTooLongNameAndDescription(): void
    {
        $this->createRole($this->tenantId, ['name' => str_repeat('ä', 50), 'description' => str_repeat('ü', 200)]);

        $this->assertRejected(self::ER_DATA_TOO_LONG, fn () => $this->createRole($this->tenantId, ['name' => str_repeat('ö', 51)]));
        $this->assertRejected(
            self::ER_DATA_TOO_LONG,
            fn () => $this->createRole($this->tenantId, ['name' => 'Andere', 'description' => str_repeat('ü', 201)]),
        );
    }

    public function testDatabaseRejectsUnknownTenantAndUpdater(): void
    {
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->createRole(999));
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->createRole($this->tenantId, ['updated_by' => 999]));

        $this->createRole($this->tenantId, ['updated_by' => $this->createSuperadmin()]);
        self::assertSame(1, $this->countRows('fe_roles'));
    }

    public function testAreasAndActionsAreAcceptedAndDeletedWithRole(): void
    {
        $roleId = $this->createRole($this->tenantId);
        $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'area' => 'device_types', 'level' => 'write']);
        $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'area' => 'search', 'level' => 'read']);
        $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'action' => 'book.move']);
        $this->insert('fe_role_locations', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'location_id' => $this->createLocation($this->tenantId)]);
        $this->insert('fe_role_device_types', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'device_type_id' => $this->createDeviceType($this->tenantId)]);

        $this->pdo()->exec('DELETE FROM fe_roles WHERE id = ' . $roleId);

        foreach (['fe_role_areas', 'fe_role_actions', 'fe_role_locations', 'fe_role_device_types'] as $table) {
            self::assertSame(0, $this->countRows($table), $table);
        }
    }

    public function testDatabaseRejectsSameAreaOrActionTwice(): void
    {
        $roleId = $this->createRole($this->tenantId);
        $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'area' => 'search', 'level' => 'read']);
        $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'action' => 'book.move']);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'area' => 'search', 'level' => 'write']),
        );
        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'action' => 'book.move']),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAreas(): iterable
    {
        yield 'Großbuchstaben' => ['Search', 'read'];
        yield 'Bindestrich' => ['due-dates', 'read'];
        yield 'Punkt' => ['book.move', 'read'];
        yield 'leer' => ['', 'read'];
        yield 'Zeilenumbruch am Ende' => ["search\n", 'read'];
        yield 'Stufe none' => ['search', 'none'];
        yield 'Stufe groß' => ['search', 'Read'];
    }

    #[DataProvider('invalidAreas')]
    public function testDatabaseRejectsInvalidArea(string $area, string $level): void
    {
        $roleId = $this->createRole($this->tenantId);

        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'area' => $area, 'level' => $level]),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidActions(): iterable
    {
        yield 'Großbuchstaben' => ['Book.move'];
        yield 'Bindestrich' => ['book-move'];
        yield 'Ziffer' => ['book2'];
        yield 'leer' => [''];
        yield 'Zeilenumbruch am Ende' => ["book.move\n"];
    }

    #[DataProvider('invalidActions')]
    public function testDatabaseRejectsInvalidAction(string $action): void
    {
        $roleId = $this->createRole($this->tenantId);

        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'action' => $action]),
        );
    }

    public function testChildRowsMustBelongToTenantOfRole(): void
    {
        $roleId = $this->createRole($this->tenantId);
        $otherTenantId = $this->createTenant('tuttlingen');
        $otherLocationId = $this->createLocation($otherTenantId);
        $otherTypeId = $this->createDeviceType($otherTenantId);

        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $otherTenantId, 'area' => 'search', 'level' => 'read']),
        );
        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $otherTenantId, 'action' => 'book.move']),
        );
        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->insert('fe_role_locations', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'location_id' => $otherLocationId]),
        );
        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->insert('fe_role_device_types', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'device_type_id' => $otherTypeId]),
        );
    }

    public function testFilteredLocationAndTypeCannotBeDeleted(): void
    {
        $roleId = $this->createRole($this->tenantId);
        $locationId = $this->createLocation($this->tenantId);
        $typeId = $this->createDeviceType($this->tenantId);
        $this->insert('fe_role_locations', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'location_id' => $locationId]);
        $this->insert('fe_role_device_types', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'device_type_id' => $typeId]);

        $this->assertRejected(self::ER_ROW_IS_REFERENCED, fn () => $this->pdo()->exec('DELETE FROM fe_locations WHERE id = ' . $locationId));
        $this->assertRejected(self::ER_ROW_IS_REFERENCED, fn () => $this->pdo()->exec('DELETE FROM fe_device_types WHERE id = ' . $typeId));
    }

    public function testUserRoleMustBelongToSameTenant(): void
    {
        $roleId = $this->createRole($this->tenantId);
        $otherTenantId = $this->createTenant('tuttlingen');

        $userId = $this->createUser($this->tenantId, ['role_id' => $roleId]);
        self::assertSame($roleId, (int) $this->pdo()->query('SELECT role_id FROM fe_users WHERE id = ' . $userId)->fetchColumn());

        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->createUser($otherTenantId, ['role_id' => $roleId]));
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->createUser($this->tenantId, ['role_id' => 999]));
    }

    public function testRoleIdIsOptionalForNow(): void
    {
        $userId = $this->createUser($this->tenantId);
        $superadminId = $this->createSuperadmin();

        $stmt = $this->pdo()->query('SELECT role_id FROM fe_users WHERE id IN (' . $userId . ', ' . $superadminId . ')');
        self::assertSame([null, null], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testRoleOfAnAccountCannotBeDeleted(): void
    {
        $roleId = $this->createRole($this->tenantId);
        $this->createUser($this->tenantId, ['role_id' => $roleId]);

        $this->assertRejected(self::ER_ROW_IS_REFERENCED, fn () => $this->pdo()->exec('DELETE FROM fe_roles WHERE id = ' . $roleId));
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    private function createRole(int $tenantId, array $overrides = []): int
    {
        return $this->insert('fe_roles', array_merge(['tenant_id' => $tenantId, 'name' => 'Gerätewart'], $overrides));
    }
}
