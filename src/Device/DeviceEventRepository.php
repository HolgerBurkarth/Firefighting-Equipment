<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Liest die Historie der Geräte (fe_device_events), immer gefiltert nach Stadt. Nur lesend: Die
 * Historie ist unveränderlich, geschrieben wird ausschließlich über DeviceEventWriter (INSERT);
 * Methoden für UPDATE oder DELETE dürfen nicht hinzukommen.
 *
 * Standorte erscheinen mit ihrem aktuellen Namen und Pfad (die Historie speichert nur IDs), der
 * Benutzer mit seinem Anzeigenamen (anonymisiert: „Gelöschter Benutzer #ID“, nie die E-Mail).
 */
final readonly class DeviceEventRepository
{
    private const string FROM = ' FROM fe_device_events e'
        . ' JOIN fe_users u ON u.id = e.user_id AND u.tenant_id = e.tenant_id'
        . ' LEFT JOIN fe_locations fl ON fl.id = e.from_location_id AND fl.tenant_id = e.tenant_id'
        . ' LEFT JOIN fe_locations fp ON fp.id = fl.parent_id AND fp.tenant_id = e.tenant_id'
        . ' LEFT JOIN fe_locations tl ON tl.id = e.to_location_id AND tl.tenant_id = e.tenant_id'
        . ' LEFT JOIN fe_locations tp ON tp.id = tl.parent_id AND tp.tenant_id = e.tenant_id'
        . ' WHERE e.tenant_id = ? AND e.device_id = ?';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Eine Seite der Historie eines Geräts, neueste zuerst (nach ID, da mehrere Einträge dieselbe
     * Sekunde haben können). Ob das Gerät zur Stadt gehört, prüft der Aufrufer.
     */
    public function listForDevice(Tenant $tenant, int $deviceId, int $limit, int $offset): DeviceEventPage
    {
        if ($limit < 1 || $offset < 0) {
            throw new \InvalidArgumentException('limit muss positiv, offset nicht negativ sein.');
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM fe_device_events e WHERE e.tenant_id = ? AND e.device_id = ?');
        $count->execute([$tenant->id, $deviceId]);
        $total = (int) $count->fetchColumn();

        // limit und offset sind geprüfte Ganzzahlen.
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT e.id, e.event_type, e.user_id, u.status AS user_status, u.display_name, u.name AS user_name,'
            . ' e.from_location_id, fl.name AS from_name, fp.name AS from_parent,'
            . ' e.to_location_id, tl.name AS to_name, tp.name AS to_parent,'
            . ' e.from_status, e.to_status, e.from_due, e.to_due, e.field, e.old_value, e.new_value, e.comment, e.created_at'
            . '%s ORDER BY e.id DESC LIMIT %d OFFSET %d',
            self::FROM,
            $limit,
            $offset,
        ));
        $stmt->execute([$tenant->id, $deviceId]);

        return new DeviceEventPage(array_map(self::hydrate(...), $stmt->fetchAll()), $total);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): DeviceEvent
    {
        return new DeviceEvent(
            (int) $row['id'],
            DeviceEventType::from((string) $row['event_type']),
            (int) $row['user_id'],
            self::userName($row),
            self::location($row, 'from'),
            self::location($row, 'to'),
            $row['from_status'] === null ? null : DeviceStatus::from((string) $row['from_status']),
            $row['to_status'] === null ? null : DeviceStatus::from((string) $row['to_status']),
            $row['from_due'] === null ? null : DeviceDate::fromDatabase((string) $row['from_due']),
            $row['to_due'] === null ? null : DeviceDate::fromDatabase((string) $row['to_due']),
            $row['field'] === null ? null : DeviceField::from((string) $row['field']),
            $row['old_value'] === null ? null : (string) $row['old_value'],
            $row['new_value'] === null ? null : (string) $row['new_value'],
            $row['comment'] === null ? null : (string) $row['comment'],
            \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row['created_at'], new \DateTimeZone('UTC'))
                ?: throw new \UnexpectedValueException(sprintf('Ungültiger Zeitstempel: %s', (string) $row['created_at'])),
        );
    }

    /**
     * Wie User::label(), aber ohne Rückgriff auf die E-Mail (die Historie sehen alle Konten der Stadt).
     *
     * @param array<string, mixed> $row
     */
    private static function userName(array $row): string
    {
        $id = (int) $row['user_id'];
        if ($row['user_status'] === 'deleted') {
            return sprintf('Gelöschter Benutzer #%d', $id);
        }

        return (string) ($row['display_name'] ?? $row['user_name'] ?? sprintf('Benutzer #%d', $id));
    }

    /**
     * @param array<string, mixed> $row
     * @param 'from'|'to'          $side
     */
    private static function location(array $row, string $side): ?DeviceEventLocation
    {
        $id = $row[$side . '_location_id'];
        if ($id === null) {
            return null;
        }
        $parent = $row[$side . '_parent'];

        return new DeviceEventLocation((int) $id, (string) $row[$side . '_name'], $parent === null ? null : (string) $parent);
    }
}
