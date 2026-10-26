<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Database\UnbufferedQuery;
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
    private const string SELECT = 'SELECT e.id, e.event_type, e.user_id, u.status AS user_status, u.display_name, u.name AS user_name,'
        . ' e.from_location_id, fl.name AS from_name, fp.name AS from_parent,'
        . ' e.to_location_id, tl.name AS to_name, tp.name AS to_parent,'
        . ' e.from_status, e.to_status, e.from_due, e.to_due, e.field, e.old_value, e.new_value, e.comment, e.created_at';

    private const string JOINS = ' FROM fe_device_events e'
        . ' JOIN fe_users u ON u.id = e.user_id AND u.tenant_id = e.tenant_id'
        . ' LEFT JOIN fe_locations fl ON fl.id = e.from_location_id AND fl.tenant_id = e.tenant_id'
        . ' LEFT JOIN fe_locations fp ON fp.id = fl.parent_id AND fp.tenant_id = e.tenant_id'
        . ' LEFT JOIN fe_locations tl ON tl.id = e.to_location_id AND tl.tenant_id = e.tenant_id'
        . ' LEFT JOIN fe_locations tp ON tp.id = tl.parent_id AND tp.tenant_id = e.tenant_id';

    private const string FROM = self::JOINS . ' WHERE e.tenant_id = ? AND e.device_id = ?';

    /** Zusätzlich das Gerät mit seinem aktuellen Typ (Export der Historie einer Stadt) */
    private const string HISTORY_FROM = self::JOINS
        . ' JOIN fe_devices d ON d.id = e.device_id AND d.tenant_id = e.tenant_id'
        . ' JOIN fe_device_types t ON t.id = d.device_type_id AND t.tenant_id = d.tenant_id'
        . ' WHERE e.tenant_id = ? AND e.created_at >= ? AND e.created_at < ?';

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
        $stmt = $this->pdo->prepare(sprintf('%s%s ORDER BY e.id DESC LIMIT %d OFFSET %d', self::SELECT, self::FROM, $limit, $offset));
        $stmt->execute([$tenant->id, $deviceId]);

        return new DeviceEventPage(array_map(self::hydrate(...), $stmt->fetchAll()), $total);
    }

    /**
     * Anzahl der Einträge, die export() mit diesem Filter liefern würde.
     */
    public function countHistory(Tenant $tenant, DeviceHistoryFilter $filter): int
    {
        [$where, $params] = self::historyWhere($tenant, $filter);
        $stmt = $this->pdo->prepare('SELECT COUNT(*)' . self::HISTORY_FROM . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Historie der Stadt für den CSV-Export, nach Zeitpunkt (bei gleicher Sekunde nach ID), auch
     * ausgesonderter Geräte; ungepuffert gelesen (siehe UnbufferedQuery: bis zum Ende keine andere
     * Abfrage über diese Verbindung).
     *
     * @return \Generator<int, DeviceHistoryEntry>
     */
    public function exportHistory(Tenant $tenant, DeviceHistoryFilter $filter): \Generator
    {
        [$where, $params] = self::historyWhere($tenant, $filter);
        $direction = $filter->newestFirst ? 'DESC' : 'ASC';

        return UnbufferedQuery::rows(
            $this->pdo,
            sprintf(
                '%s, d.id AS device_id, d.identifier AS device_identifier, t.name AS type_name%s%s ORDER BY e.created_at %s, e.id %s',
                self::SELECT,
                self::HISTORY_FROM,
                $where,
                $direction,
                $direction,
            ),
            $params,
            static fn (array $row): DeviceHistoryEntry => new DeviceHistoryEntry(
                self::hydrate($row),
                (int) $row['device_id'],
                (string) $row['device_identifier'],
                (string) $row['type_name'],
            ),
        );
    }

    /**
     * @return array{string, list<int|string>} weitere Bedingungen (SQL aus diesem Code) und alle
     *                                         Parameter von HISTORY_FROM an
     */
    private static function historyWhere(Tenant $tenant, DeviceHistoryFilter $filter): array
    {
        $where = '';
        $params = [$tenant->id, $filter->fromUtc, $filter->untilUtc];
        if ($filter->deviceId !== null) {
            $where .= ' AND e.device_id = ?';
            $params[] = $filter->deviceId;
        }
        if ($filter->deviceTypeId !== null) {
            $where .= ' AND d.device_type_id = ?';
            $params[] = $filter->deviceTypeId;
        }
        if ($filter->locationId !== null) {
            if ($filter->includeSublocations) {
                $where .= ' AND (e.from_location_id = ? OR fl.parent_id = ? OR e.to_location_id = ? OR tl.parent_id = ?)';
                array_push($params, ...array_fill(0, 4, $filter->locationId));
            } else {
                $where .= ' AND (e.from_location_id = ? OR e.to_location_id = ?)';
                array_push($params, $filter->locationId, $filter->locationId);
            }
        }

        return [$where, $params];
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
