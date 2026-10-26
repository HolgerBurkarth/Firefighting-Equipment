<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Schreibt die Historie der Geräte (fe_device_events). Die Historie ist unveränderlich: Diese
 * Klasse kennt nur INSERT; Methoden für UPDATE oder DELETE dürfen nicht hinzukommen. Läuft in der
 * Transaktion des Aufrufers (DeviceRepository), zusammen mit der Änderung am Gerät.
 */
final readonly class DeviceEventWriter
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Gerät angelegt: Ziel-Standort und -Status.
     */
    public function created(Tenant $tenant, int $deviceId, int $userId, int $locationId, DeviceStatus $status, ?string $comment): void
    {
        $this->insert($tenant, $deviceId, $userId, 'created', [
            'to_location_id' => $locationId,
            'to_status' => $status->value,
        ], $comment);
    }

    /**
     * Ein Stammdatenfeld geändert; Werte als Text (beim Gerätetyp der Name), null = leer.
     */
    public function fieldChanged(Tenant $tenant, int $deviceId, int $userId, DeviceField $field, ?string $old, ?string $new, ?string $comment): void
    {
        $this->insert($tenant, $deviceId, $userId, 'field_changed', [
            'field' => $field->value,
            'old_value' => $old,
            'new_value' => $new,
        ], $comment);
    }

    /**
     * Nächste Prüfung fällig geändert (null = kein Datum).
     */
    public function dueChanged(Tenant $tenant, int $deviceId, int $userId, ?DeviceDate $from, ?DeviceDate $to, ?string $comment): void
    {
        $this->insert($tenant, $deviceId, $userId, 'due_changed', [
            'from_due' => $from?->value,
            'to_due' => $to?->value,
        ], $comment);
    }

    /**
     * @param array<string, int|string|null> $values Spalten aus diesem Code, nie aus Eingaben
     */
    private function insert(Tenant $tenant, int $deviceId, int $userId, string $type, array $values, ?string $comment): void
    {
        $values = [
            'tenant_id' => $tenant->id,
            'device_id' => $deviceId,
            'event_type' => $type,
            'user_id' => $userId,
            ...$values,
            'comment' => $comment,
        ];
        $this->pdo->prepare(sprintf(
            'INSERT INTO fe_device_events (%s, created_at) VALUES (%s, UTC_TIMESTAMP())',
            implode(', ', array_keys($values)),
            implode(', ', array_fill(0, count($values), '?')),
        ))->execute(array_values($values));
    }
}
