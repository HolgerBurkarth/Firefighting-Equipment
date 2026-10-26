<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

/**
 * Gerätetypen, Standorte und Geräte direkt per SQL anlegen (an jeder Fachlogik vorbei). Für Klassen,
 * die von AuthSchemaTestCase erben (dort insert()).
 */
trait DeviceRows
{
    private int $typeCounter = 0;
    private int $locationCounter = 0;
    private int $deviceCounter = 0;

    /**
     * @param array<string, scalar|null> $overrides
     */
    protected function createDeviceType(int $tenantId, array $overrides = []): int
    {
        return $this->insert('fe_device_types', array_merge([
            'tenant_id' => $tenantId,
            'name' => sprintf('Gerätetyp %d', ++$this->typeCounter),
        ], $overrides));
    }

    /**
     * Standort der Ebene 1.
     *
     * @param array<string, scalar|null> $overrides
     */
    protected function createLocation(int $tenantId, array $overrides = []): int
    {
        return $this->insert('fe_locations', array_merge([
            'tenant_id' => $tenantId,
            'kind' => 'vehicle',
            'name' => sprintf('Standort %d', ++$this->locationCounter),
            'level' => 1,
        ], $overrides));
    }

    /**
     * Unterstandort (Ebene 2), standardmäßig von derselben Art wie 'vehicle'.
     *
     * @param array<string, scalar|null> $overrides
     */
    protected function createSubLocation(int $tenantId, int $parentId, array $overrides = []): int
    {
        return $this->createLocation($tenantId, array_merge([
            'parent_id' => $parentId,
            'level' => 2,
            'parent_level' => 1,
        ], $overrides));
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    protected function createDevice(int $tenantId, int $typeId, int $locationId, array $overrides = []): int
    {
        return $this->insert('fe_devices', array_merge([
            'tenant_id' => $tenantId,
            'identifier' => sprintf('MA-%02d', ++$this->deviceCounter),
            'device_type_id' => $typeId,
            'location_id' => $locationId,
            'status' => 'ready',
        ], $overrides));
    }
}
