<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Tests\Integration\User\AuthSchemaTestCase;

/**
 * Hilfen für die Schema-Tests der Geräte-Tabellen: Gerätetypen, Standorte, Geräte (DeviceRows) und
 * Ereignisse direkt per SQL anlegen (an jeder Fachlogik vorbei).
 */
abstract class DeviceSchemaTestCase extends AuthSchemaTestCase
{
    use DeviceRows;

    protected const int ER_TRUNCATED_WRONG_VALUE = 1292;

    /**
     * @param array<string, scalar|null> $values Spalten des Ereignisses außer Stadt, Gerät und Benutzer
     */
    protected function createEvent(int $tenantId, int $deviceId, int $userId, array $values): int
    {
        return $this->insert('fe_device_events', array_merge([
            'tenant_id' => $tenantId,
            'device_id' => $deviceId,
            'user_id' => $userId,
        ], $values));
    }

    protected function deviceValue(int $id, string $column): mixed
    {
        $stmt = $this->pdo()->prepare(sprintf('SELECT %s FROM fe_devices WHERE id = ?', $column));
        $stmt->execute([$id]);

        return $stmt->fetchColumn();
    }
}
