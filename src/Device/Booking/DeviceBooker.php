<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Booking;

use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceNotFoundException;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Buchung mit Wiederholung über die client_ref (POST devices/{id}/state und POST devices/bulk/state):
 * Ist die Buchung schon gespeichert, wird ihr Ergebnis wiederholt (replayed, nichts geschrieben);
 * dieselbe client_ref mit anderem Inhalt, Gerät oder Konto ist ein Fehler. Wird dieselbe client_ref
 * gleichzeitig verarbeitet, gewinnt die andere Anfrage, und ihr Ergebnis wird wiederholt.
 */
final readonly class DeviceBooker
{
    public function __construct(
        private Tenant $tenant,
        private DeviceRepository $devices,
        private DeviceBookingRepository $bookings,
        private DeviceNoticeRepository $notices,
    ) {
    }

    /**
     * Gespeichertes Ergebnis derselben Buchung, ohne client_ref oder ohne Quittung null.
     *
     * @throws ClientRefConflictException andere Buchung mit derselben client_ref
     * @throws DeviceNotFoundException
     */
    public function previous(?ClientRef $clientRef, int $userId, int $deviceId, string $hash): ?BookingResult
    {
        $stored = $clientRef === null ? null : $this->bookings->find($this->tenant, $clientRef);

        return $stored === null ? null : $this->replay($stored, $clientRef, $userId, $deviceId, $hash);
    }

    /**
     * Bucht nach DeviceRepository::book(); bei gleichzeitig vergebener client_ref das Ergebnis der
     * anderen Anfrage (replayed).
     *
     * @throws DeviceException
     */
    public function book(int $userId, int $deviceId, int $version, DeviceStateChange $change, ?string $comment, BookingRequest $booking): BookingResult
    {
        try {
            return $this->devices->book($this->tenant, $userId, $deviceId, $version, $change, $comment, $booking);
        } catch (DuplicateClientRefException) {
            $clientRef = $booking->clientRef ?? throw new \LogicException('client_ref fehlt.');
            $stored = $this->bookings->find($this->tenant, $clientRef) ?? throw new \LogicException('Quittung fehlt.');

            return $this->replay($stored, $clientRef, $userId, $deviceId, $booking->requestHash);
        }
    }

    /**
     * @throws ClientRefConflictException
     * @throws DeviceNotFoundException
     */
    private function replay(StoredBooking $stored, ClientRef $clientRef, int $userId, int $deviceId, string $hash): BookingResult
    {
        if (!$stored->matches($userId, $deviceId, $hash)) {
            throw new ClientRefConflictException();
        }
        $device = $this->devices->find($this->tenant, $deviceId) ?? throw DeviceNotFoundException::forId($deviceId);

        return new BookingResult(
            $device,
            $stored->outcome,
            $stored->reason,
            $stored->staleConfirmed,
            $clientRef,
            replayed: true,
            eventId: $stored->eventId,
            noticeId: $this->notices->idForBooking($this->tenant, $stored->id),
        );
    }
}
