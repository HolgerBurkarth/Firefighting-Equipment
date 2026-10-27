<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Booking;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Quittungen der Buchungen mit client_ref (fe_device_bookings), immer gefiltert nach Stadt. Nur
 * INSERT und Lesen; eine Quittung wird nie geändert.
 */
final readonly class DeviceBookingRepository
{
    private const int ER_DUP_ENTRY = 1062;

    private const string REF_INDEX = 'uq_fe_device_bookings_tenant_ref';

    public function __construct(private \PDO $pdo)
    {
    }

    public function find(Tenant $tenant, ClientRef $clientRef): ?StoredBooking
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, device_id, request_hash, outcome, reason, stale_confirmed, event_id'
            . ' FROM fe_device_bookings WHERE tenant_id = ? AND client_ref = ?',
        );
        $stmt->execute([$tenant->id, $clientRef->value]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new StoredBooking(
            (int) $row['id'],
            (int) $row['user_id'],
            (int) $row['device_id'],
            (string) $row['request_hash'],
            BookingOutcome::from((string) $row['outcome']),
            $row['reason'] === null ? null : BookingReason::from((string) $row['reason']),
            (int) $row['stale_confirmed'] === 1,
            $row['event_id'] === null ? null : (int) $row['event_id'],
        );
    }

    /**
     * Speichert die Quittung in der Transaktion des Aufrufers.
     *
     * @throws DuplicateClientRefException client_ref gleichzeitig vergeben
     */
    public function insert(
        Tenant $tenant,
        ClientRef $clientRef,
        int $userId,
        int $deviceId,
        string $requestHash,
        BookingOutcome $outcome,
        ?BookingReason $reason,
        bool $staleConfirmed,
        ?int $eventId,
    ): int {
        try {
            $this->pdo->prepare(
                'INSERT INTO fe_device_bookings (tenant_id, client_ref, user_id, device_id, request_hash, outcome, reason,'
                . ' stale_confirmed, event_id, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            )->execute([
                $tenant->id,
                $clientRef->value,
                $userId,
                $deviceId,
                $requestHash,
                $outcome->value,
                $reason?->value,
                $staleConfirmed ? 1 : 0,
                $eventId,
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === self::ER_DUP_ENTRY && str_contains((string) ($e->errorInfo[2] ?? ''), self::REF_INDEX)) {
                throw new DuplicateClientRefException('client_ref gleichzeitig vergeben.', previous: $e);
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }
}
