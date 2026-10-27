<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Booking;

/**
 * Gespeicherte Quittung einer Buchung (fe_device_bookings).
 */
final readonly class StoredBooking
{
    public function __construct(
        public int $id,
        public int $userId,
        public int $deviceId,
        /** SHA-256, binär */
        public string $requestHash,
        public BookingOutcome $outcome,
        public ?BookingReason $reason,
        public bool $staleConfirmed,
        public ?int $eventId,
    ) {
    }

    /**
     * Dieselbe Buchung: gleiches Konto, gleiches Gerät, gleicher Inhalt.
     */
    public function matches(int $userId, int $deviceId, string $requestHash): bool
    {
        return $this->userId === $userId && $this->deviceId === $deviceId && hash_equals($this->requestHash, $requestHash);
    }
}
