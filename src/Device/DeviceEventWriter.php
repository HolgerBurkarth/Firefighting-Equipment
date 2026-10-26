<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Schreibt die Historie der Geräte (fe_device_events). Die Historie ist unveränderlich: Diese
 * Klasse kennt nur INSERT; Methoden für UPDATE oder DELETE dürfen nicht hinzukommen. Läuft in der
 * Transaktion des Aufrufers (DeviceRepository, DeviceImport), zusammen mit der Änderung am Gerät.
 *
 * Gepuffert (für den CSV-Import): Ereignisse werden gesammelt und in INSERTs mit bis zu BATCH_ROWS
 * Zeilen geschrieben, in der Reihenfolge der Aufrufe; der Aufrufer muss am Ende flush() aufrufen.
 */
final class DeviceEventWriter
{
    /** Zeilen je INSERT im gepufferten Betrieb */
    public const int BATCH_ROWS = 500;

    private const array COLUMNS = [
        'tenant_id', 'device_id', 'event_type', 'user_id', 'from_location_id', 'to_location_id', 'from_status',
        'to_status', 'from_due', 'to_due', 'field', 'old_value', 'new_value', 'comment',
    ];

    /** @var list<list<int|string|null>> */
    private array $pending = [];

    public function __construct(private readonly \PDO $pdo, private readonly bool $buffered = false)
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
     * Gerät per CSV-Import angelegt: Ziel-Standort und -Status.
     */
    public function imported(Tenant $tenant, int $deviceId, int $userId, int $locationId, DeviceStatus $status, ?string $comment): void
    {
        $this->insert($tenant, $deviceId, $userId, 'imported', [
            'to_location_id' => $locationId,
            'to_status' => $status->value,
        ], $comment);
    }

    /**
     * Umbuchung und/oder Statusänderung in einem Eintrag (auch Aussondern und Wiederaufnehmen): je
     * Paar beide Werte (verschieden) oder keiner, mindestens ein Paar (chk_fe_device_events_state).
     */
    public function stateChanged(
        Tenant $tenant,
        int $deviceId,
        int $userId,
        ?int $fromLocationId,
        ?int $toLocationId,
        ?DeviceStatus $fromStatus,
        ?DeviceStatus $toStatus,
        ?string $comment,
    ): void {
        $this->insert($tenant, $deviceId, $userId, 'state_changed', [
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus?->value,
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
     * Schreibt gesammelte Ereignisse (gepufferter Betrieb).
     */
    public function flush(): void
    {
        foreach (array_chunk($this->pending, self::BATCH_ROWS) as $rows) {
            $this->write($rows);
        }
        $this->pending = [];
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
        $row = array_map(static fn (string $column): int|string|null => $values[$column] ?? null, self::COLUMNS);

        if (!$this->buffered) {
            $this->write([$row]);

            return;
        }
        $this->pending[] = $row;
        if (count($this->pending) >= self::BATCH_ROWS) {
            $this->flush();
        }
    }

    /**
     * @param list<list<int|string|null>> $rows
     */
    private function write(array $rows): void
    {
        $placeholders = '(' . implode(', ', array_fill(0, count(self::COLUMNS), '?')) . ', UTC_TIMESTAMP())';
        $this->pdo->prepare(sprintf(
            'INSERT INTO fe_device_events (%s, created_at) VALUES %s',
            implode(', ', self::COLUMNS),
            implode(', ', array_fill(0, count($rows), $placeholders)),
        ))->execute(array_merge(...$rows));
    }
}
