<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Notice;

use FirefightingEquipment\Device\DeviceEventLocation;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Hinweise an die Admins (fe_device_notices) und ihr Verlauf (fe_device_notice_actions), immer
 * gefiltert nach Stadt. Angelegt werden sie nur in der Transaktion einer Buchung
 * (DeviceRepository::book); erledigt bzw. wieder geöffnet in einer eigenen Transaktion, jeweils mit
 * einem Eintrag im Verlauf. Es gibt bewusst keine Methode zum Löschen und kein automatisches
 * Erledigen.
 */
final readonly class DeviceNoticeRepository
{
    private const string SELECT = 'SELECT n.id, n.kind, n.status, n.stale_confirmed, n.device_id, d.identifier AS device_identifier,'
        . ' t.name AS type_name, b.client_ref, n.event_id, n.occurred_at, n.requested_status, n.other_occurred_at, n.other_status,'
        . ' n.created_at, n.updated_at,'
        . ' u.id AS u_id, u.status AS u_status, u.display_name AS u_display_name, u.name AS u_name, u.email AS u_email,'
        . ' ou.id AS ou_id, ou.status AS ou_status, ou.display_name AS ou_display_name, ou.name AS ou_name, ou.email AS ou_email,'
        . ' n.requested_location_id, rl.name AS requested_name, rp.name AS requested_parent,'
        . ' n.other_location_id, ol.name AS other_name, op.name AS other_parent';

    private const string FROM = ' FROM fe_device_notices n'
        . ' JOIN fe_devices d ON d.id = n.device_id AND d.tenant_id = n.tenant_id'
        . ' JOIN fe_device_types t ON t.id = d.device_type_id AND t.tenant_id = d.tenant_id'
        . ' JOIN fe_device_bookings b ON b.id = n.booking_id AND b.tenant_id = n.tenant_id'
        . ' JOIN fe_users u ON u.id = n.user_id AND u.tenant_id = n.tenant_id'
        . ' LEFT JOIN fe_users ou ON ou.id = n.other_user_id AND ou.tenant_id = n.tenant_id'
        . ' LEFT JOIN fe_locations rl ON rl.id = n.requested_location_id AND rl.tenant_id = n.tenant_id'
        . ' LEFT JOIN fe_locations rp ON rp.id = rl.parent_id AND rp.tenant_id = n.tenant_id'
        . ' JOIN fe_locations ol ON ol.id = n.other_location_id AND ol.tenant_id = n.tenant_id'
        . ' LEFT JOIN fe_locations op ON op.id = ol.parent_id AND op.tenant_id = n.tenant_id'
        . ' WHERE n.tenant_id = ?';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Legt einen offenen Hinweis an (in der Transaktion des Aufrufers).
     */
    public function create(Tenant $tenant, NewNotice $notice): int
    {
        $this->pdo->prepare(
            'INSERT INTO fe_device_notices (tenant_id, device_id, booking_id, event_id, kind, stale_confirmed, user_id, occurred_at,'
            . ' requested_location_id, requested_status, other_user_id, other_occurred_at, other_location_id, other_status, status,'
            . ' created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        )->execute([
            $tenant->id,
            $notice->deviceId,
            $notice->bookingId,
            $notice->eventId,
            $notice->kind->value,
            $notice->staleConfirmed ? 1 : 0,
            $notice->userId,
            $notice->occurredAt->sql(),
            $notice->requested->locationId,
            $notice->requested->status?->value,
            $notice->other->userId,
            $notice->other->at->format('Y-m-d H:i:s'),
            $notice->other->locationId,
            $notice->other->status->value,
            NoticeStatus::Open->value,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Eine Seite der Hinweise, neueste zuerst (nach ID), mit Verlauf.
     *
     * @param NoticeStatus|null $status null = alle
     */
    public function list(Tenant $tenant, ?NoticeStatus $status, int $limit, int $offset): DeviceNoticePage
    {
        if ($limit < 1 || $offset < 0) {
            throw new \InvalidArgumentException('limit muss positiv, offset nicht negativ sein.');
        }
        $where = $status === null ? '' : ' AND n.status = ?';
        $params = $status === null ? [$tenant->id] : [$tenant->id, $status->value];

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM fe_device_notices n WHERE n.tenant_id = ?' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        // limit und offset sind geprüfte Ganzzahlen.
        $stmt = $this->pdo->prepare(sprintf('%s%s%s ORDER BY n.id DESC LIMIT %d OFFSET %d', self::SELECT, self::FROM, $where, $limit, $offset));
        $stmt->execute($params);

        return new DeviceNoticePage($this->withHistory($tenant, $stmt->fetchAll()), $total);
    }

    public function find(Tenant $tenant, int $id): ?DeviceNotice
    {
        $stmt = $this->pdo->prepare(self::SELECT . self::FROM . ' AND n.id = ?');
        $stmt->execute([$tenant->id, $id]);

        return $this->withHistory($tenant, $stmt->fetchAll())[0] ?? null;
    }

    /**
     * Hinweis zu einer Buchung (Quittung), falls einer angelegt wurde.
     */
    public function idForBooking(Tenant $tenant, int $bookingId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM fe_device_notices WHERE tenant_id = ? AND booking_id = ?');
        $stmt->execute([$tenant->id, $bookingId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function countOpen(Tenant $tenant): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM fe_device_notices WHERE tenant_id = ? AND status = ?');
        $stmt->execute([$tenant->id, NoticeStatus::Open->value]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Erledigt bzw. öffnet einen Hinweis wieder (eigene Transaktion) und schreibt den Verlauf. Hat
     * der Hinweis den Ziel-Status schon, ändert sich nichts (wiederholbar, kein Eintrag).
     *
     * @param string|null $note nur beim Erledigen (geprüft vom Aufrufer)
     *
     * @throws NoticeNotFoundException
     */
    public function change(Tenant $tenant, int $id, int $userId, NoticeChange $change, ?string $note): DeviceNotice
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('DeviceNoticeRepository startet eine eigene Transaktion; keine offene Transaktion erlaubt.');
        }
        if ($note !== null && $change !== NoticeChange::Resolve) {
            throw new \LogicException('Eine Notiz gibt es nur beim Erledigen.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT status FROM fe_device_notices WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $stmt->execute([$id, $tenant->id]);
            $status = $stmt->fetchColumn();
            if ($status === false) {
                throw NoticeNotFoundException::forId($id);
            }
            if ($status !== $change->status()->value) {
                $this->pdo->prepare('UPDATE fe_device_notices SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ?')
                    ->execute([$change->status()->value, $id, $tenant->id]);
                $this->pdo->prepare(
                    'INSERT INTO fe_device_notice_actions (tenant_id, notice_id, action, user_id, note, created_at)'
                    . ' VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                )->execute([$tenant->id, $id, $change->value, $userId, $note]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($tenant, $id) ?? throw NoticeNotFoundException::forId($id);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<DeviceNotice>
     */
    private function withHistory(Tenant $tenant, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT a.id, a.notice_id, a.action, a.note, a.created_at,'
            . ' u.id AS u_id, u.status AS u_status, u.display_name AS u_display_name, u.name AS u_name, u.email AS u_email'
            . ' FROM fe_device_notice_actions a JOIN fe_users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id'
            . ' WHERE a.tenant_id = ? AND a.notice_id IN (%s) ORDER BY a.id',
            implode(', ', array_fill(0, count($ids), '?')),
        ));
        $stmt->execute([$tenant->id, ...$ids]);
        $history = [];
        foreach ($stmt->fetchAll() as $row) {
            $history[(int) $row['notice_id']][] = new NoticeHistoryEntry(
                (int) $row['id'],
                NoticeChange::from((string) $row['action']),
                NoticeUser::fromRow($row, 'u_') ?? throw new \LogicException('Konto fehlt.'),
                $row['note'] === null ? null : (string) $row['note'],
                self::utc((string) $row['created_at']),
            );
        }

        return array_map(fn (array $row): DeviceNotice => self::hydrate($row, $history[(int) $row['id']] ?? []), $rows);
    }

    /**
     * @param array<string, mixed>     $row
     * @param list<NoticeHistoryEntry> $history
     */
    private static function hydrate(array $row, array $history): DeviceNotice
    {
        return new DeviceNotice(
            (int) $row['id'],
            NoticeKind::from((string) $row['kind']),
            NoticeStatus::from((string) $row['status']),
            (int) $row['stale_confirmed'] === 1,
            (int) $row['device_id'],
            (string) $row['device_identifier'],
            (string) $row['type_name'],
            $row['client_ref'] === null ? null : (string) $row['client_ref'],
            $row['event_id'] === null ? null : (int) $row['event_id'],
            NoticeUser::fromRow($row, 'u_') ?? throw new \LogicException('Konto fehlt.'),
            self::utc((string) $row['occurred_at']),
            self::location($row, 'requested'),
            $row['requested_status'] === null ? null : DeviceStatus::from((string) $row['requested_status']),
            NoticeUser::fromRow($row, 'ou_'),
            self::utc((string) $row['other_occurred_at']),
            self::location($row, 'other') ?? throw new \LogicException('Standort fehlt.'),
            DeviceStatus::from((string) $row['other_status']),
            self::utc((string) $row['created_at']),
            self::utc((string) $row['updated_at']),
            $history,
        );
    }

    /**
     * @param array<string, mixed>   $row
     * @param 'requested'|'other'    $side
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

    private static function utc(string $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'))
            ?: throw new \UnexpectedValueException(sprintf('Ungültiger Zeitstempel: %s', $value));
    }
}
