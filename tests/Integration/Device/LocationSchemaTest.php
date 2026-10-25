<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_locations: höchstens zwei Ebenen, Unterstandort mit der Art und Stadt des übergeordneten
 * Standorts, Name je Stadt und übergeordnetem Standort eindeutig.
 */
#[CoversNothing]
final class LocationSchemaTest extends DeviceSchemaTestCase
{
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant();
    }

    public function testTopLevelAndSubLocationAreAcceptedWithDefaults(): void
    {
        $parentId = $this->createLocation($this->tenantId, ['kind' => 'room', 'name' => 'Gerätehaus']);
        $childId = $this->createSubLocation($this->tenantId, $parentId, ['kind' => 'room', 'name' => 'Lager Atemschutz']);

        $rows = $this->pdo()->query('SELECT id, parent_id, parent_key, level, parent_level, active FROM fe_locations ORDER BY level')->fetchAll();

        self::assertSame([null, 0, 1, null, 1], [$rows[0]['parent_id'], $rows[0]['parent_key'], $rows[0]['level'], $rows[0]['parent_level'], $rows[0]['active']]);
        self::assertSame([$parentId, $parentId, 2, 1, 1], [$rows[1]['parent_id'], $rows[1]['parent_key'], $rows[1]['level'], $rows[1]['parent_level'], $rows[1]['active']]);
        self::assertSame($childId, $rows[1]['id']);
    }

    public function testAllKindsAreAccepted(): void
    {
        foreach (['vehicle', 'room', 'external'] as $kind) {
            $parentId = $this->createLocation($this->tenantId, ['kind' => $kind]);
            $this->createSubLocation($this->tenantId, $parentId, ['kind' => $kind]);
        }

        self::assertSame(6, $this->countRows('fe_locations'));
    }

    public function testSameNameOnOtherLevelOrUnderOtherParentOrInOtherTenantIsAccepted(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20']);
        $tlf = $this->createLocation($this->tenantId, ['name' => 'TLF 3000']);
        $this->createLocation($this->tenantId, ['name' => 'Geräteraum G1']);
        $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Geräteraum G1']);
        $this->createSubLocation($this->tenantId, $tlf, ['name' => 'Geräteraum G1']);
        $this->createLocation($this->createTenant('tuttlingen'), ['name' => 'HLF 20']);

        self::assertSame(6, $this->countRows('fe_locations'));
    }

    public function testDatabaseRejectsDuplicateTopLevelNameIgnoringCase(): void
    {
        $this->createLocation($this->tenantId, ['name' => 'HLF 20']);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->createLocation($this->tenantId, ['kind' => 'room', 'name' => 'hlf 20']),
        );
    }

    public function testDatabaseRejectsDuplicateNameUnderSameParent(): void
    {
        $parentId = $this->createLocation($this->tenantId);
        $this->createSubLocation($this->tenantId, $parentId, ['name' => 'Geräteraum G1']);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->createSubLocation($this->tenantId, $parentId, ['name' => 'GERÄTERAUM G1']),
        );
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidTopLevelLocations(): iterable
    {
        yield 'unbekannte Art' => [['kind' => 'car']];
        yield 'Art großgeschrieben' => [['kind' => 'Vehicle']];
        yield 'leerer Name' => [['name' => '  ']];
        yield 'Ebene 2 ohne übergeordneten Standort' => [['level' => 2]];
        yield 'Ebene 0' => [['level' => 0]];
        yield 'parent_level ohne übergeordneten Standort' => [['parent_level' => 1]];
        yield 'active 2' => [['active' => 2]];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidTopLevelLocations')]
    public function testDatabaseRejectsInvalidTopLevelLocation(array $overrides): void
    {
        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->createLocation($this->tenantId, $overrides),
        );
        self::assertSame(0, $this->countRows('fe_locations'));
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidSubLocations(): iterable
    {
        yield 'Ebene 1 mit übergeordnetem Standort' => [['level' => 1]];
        yield 'Ebene 3' => [['level' => 3]];
        yield 'parent_level fehlt' => [['parent_level' => null]];
        yield 'parent_level 2' => [['parent_level' => 2]];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidSubLocations')]
    public function testDatabaseRejectsInvalidSubLocation(array $overrides): void
    {
        $parentId = $this->createLocation($this->tenantId);

        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->createSubLocation($this->tenantId, $parentId, $overrides),
        );
        self::assertSame(1, $this->countRows('fe_locations'));
    }

    public function testDatabaseRejectsSubLocationOfSubLocation(): void
    {
        $childId = $this->createSubLocation($this->tenantId, $this->createLocation($this->tenantId));

        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->createSubLocation($this->tenantId, $childId),
        );
    }

    public function testDatabaseRejectsParentOfOtherTenant(): void
    {
        $foreignParentId = $this->createLocation($this->createTenant('tuttlingen'));

        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->createSubLocation($this->tenantId, $foreignParentId),
        );
    }

    public function testDatabaseRejectsSubLocationOfOtherKind(): void
    {
        $parentId = $this->createLocation($this->tenantId, ['kind' => 'vehicle']);

        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->createSubLocation($this->tenantId, $parentId, ['kind' => 'room']),
        );
    }

    public function testDatabaseRejectsChangingKindOfSubLocation(): void
    {
        $childId = $this->createSubLocation($this->tenantId, $this->createLocation($this->tenantId));

        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->pdo()->exec("UPDATE fe_locations SET kind = 'room' WHERE id = " . $childId),
        );
    }

    public function testKindOfParentCanOnlyChangeWithoutSubLocations(): void
    {
        $parentId = $this->createLocation($this->tenantId);
        $emptyId = $this->createLocation($this->tenantId);
        $this->createSubLocation($this->tenantId, $parentId);

        $this->assertRejected(
            self::ER_ROW_IS_REFERENCED,
            fn () => $this->pdo()->exec("UPDATE fe_locations SET kind = 'room' WHERE id = " . $parentId),
        );
        self::assertSame(1, $this->pdo()->exec("UPDATE fe_locations SET kind = 'room' WHERE id = " . $emptyId));
    }

    public function testDatabaseRejectsMovingParentWithSubLocationsBelowOtherLocation(): void
    {
        $parentId = $this->createLocation($this->tenantId);
        $otherId = $this->createLocation($this->tenantId);
        $this->createSubLocation($this->tenantId, $parentId);

        $this->assertRejected(
            self::ER_ROW_IS_REFERENCED,
            fn () => $this->pdo()->exec(sprintf(
                'UPDATE fe_locations SET parent_id = %d, level = 2, parent_level = 1 WHERE id = %d',
                $otherId,
                $parentId,
            )),
        );
    }

    public function testDatabaseRejectsTooLongName(): void
    {
        $this->createLocation($this->tenantId, ['name' => str_repeat('ü', 100)]);

        $this->assertRejected(
            self::ER_DATA_TOO_LONG,
            fn () => $this->createLocation($this->tenantId, ['name' => str_repeat('ö', 101)]),
        );
    }

    public function testDatabaseRejectsDeletingLocationInUse(): void
    {
        $parentId = $this->createLocation($this->tenantId);
        $this->createSubLocation($this->tenantId, $parentId);
        $deviceLocationId = $this->createLocation($this->tenantId);
        $this->createDevice($this->tenantId, $this->createDeviceType($this->tenantId), $deviceLocationId);

        foreach ([$parentId, $deviceLocationId] as $id) {
            $this->assertRejected(
                self::ER_ROW_IS_REFERENCED,
                fn () => $this->pdo()->exec('DELETE FROM fe_locations WHERE id = ' . $id),
            );
        }
    }
}
