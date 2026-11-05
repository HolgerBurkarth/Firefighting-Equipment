<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

/**
 * Konten mit einer Rolle mit Datenfilter „Sieht nur …“ in Tests auf Basis von DeviceApiTestCase:
 * alle Rechte außer Verwaltung (wie ein Gerätewart, mit Log-Mails), dazu die angegebenen Filter.
 */
trait FilteredRoles
{
    private int $filteredRoleCounter = 0;

    /**
     * @param array<string, int>         $flags         filter_locations, filter_device_types
     * @param list<int>                  $locationIds
     * @param list<int>                  $deviceTypeIds
     * @param array<string, string>      $extraAreas
     * @param list<string>               $extraActions
     * @param array<string, scalar|null> $account       weitere Spalten des Kontos (z. B. notify_device_log)
     *
     * @return int ID des Kontos
     */
    private function filteredAccount(
        string $email,
        array $flags,
        array $locationIds,
        array $deviceTypeIds,
        array $extraAreas = [],
        array $extraActions = [],
        array $account = [],
    ): int {
        $tenantId = $this->tenant('immendingen')->id;
        $roleId = $this->insert('fe_roles', ['tenant_id' => $tenantId, 'name' => 'Filterrolle ' . ++$this->filteredRoleCounter, ...$flags]);
        $areas = ['dashboard' => 'read', 'search' => 'read', 'devices' => 'write', 'locations' => 'read', 'device_types' => 'read', 'due' => 'write', 'log' => 'write', ...$extraAreas];
        foreach ($areas as $area => $level) {
            $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $tenantId, 'area' => $area, 'level' => $level]);
        }
        foreach (['book.move', 'book.status', 'device.create', 'device.edit', 'device.retire', 'device.code', 'devices.bulk', 'mail.log', ...$extraActions] as $action) {
            $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $tenantId, 'action' => $action]);
        }
        foreach ($locationIds as $locationId) {
            $this->insert('fe_role_locations', ['role_id' => $roleId, 'tenant_id' => $tenantId, 'location_id' => $locationId]);
        }
        foreach ($deviceTypeIds as $typeId) {
            $this->insert('fe_role_device_types', ['role_id' => $roleId, 'tenant_id' => $tenantId, 'device_type_id' => $typeId]);
        }

        return $this->account('immendingen', $email, ['role_id' => $roleId, ...$account]);
    }
}
