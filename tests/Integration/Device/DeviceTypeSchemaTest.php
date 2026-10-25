<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_device_types: Name je Stadt eindeutig (ohne Groß- und Kleinschreibung), nie gelöscht.
 */
#[CoversNothing]
final class DeviceTypeSchemaTest extends DeviceSchemaTestCase
{
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant();
    }

    public function testValidTypeIsAcceptedWithDefaults(): void
    {
        $id = $this->createDeviceType($this->tenantId, ['name' => 'Wärmebildkamera']);

        $row = $this->pdo()->query('SELECT name, active FROM fe_device_types WHERE id = ' . $id)->fetch();

        self::assertSame('Wärmebildkamera', $row['name']);
        self::assertSame(1, $row['active']);
    }

    public function testSameNameInOtherTenantIsAccepted(): void
    {
        $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $this->createDeviceType($this->createTenant('tuttlingen'), ['name' => 'Funkgerät']);

        self::assertSame(2, $this->countRows('fe_device_types'));
    }

    public function testDatabaseRejectsDuplicateNameIgnoringCase(): void
    {
        $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->createDeviceType($this->tenantId, ['name' => 'FUNKGERÄT']),
        );
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidTypes(): iterable
    {
        yield 'leerer Name' => [['name' => '   ']];
        yield 'active 2' => [['active' => 2]];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidTypes')]
    public function testDatabaseRejectsInvalidType(array $overrides): void
    {
        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->createDeviceType($this->tenantId, $overrides),
        );
        self::assertSame(0, $this->countRows('fe_device_types'));
    }

    public function testDatabaseRejectsTooLongName(): void
    {
        $this->createDeviceType($this->tenantId, ['name' => str_repeat('ä', 50)]);

        $this->assertRejected(
            self::ER_DATA_TOO_LONG,
            fn () => $this->createDeviceType($this->tenantId, ['name' => str_repeat('ö', 51)]),
        );
    }

    public function testDatabaseRejectsUnknownTenant(): void
    {
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->createDeviceType(999));
    }

    public function testDatabaseRejectsDeletingUsedType(): void
    {
        $typeId = $this->createDeviceType($this->tenantId);
        $this->createDevice($this->tenantId, $typeId, $this->createLocation($this->tenantId));

        $this->assertRejected(
            self::ER_ROW_IS_REFERENCED,
            fn () => $this->pdo()->exec('DELETE FROM fe_device_types WHERE id = ' . $typeId),
        );
    }
}
