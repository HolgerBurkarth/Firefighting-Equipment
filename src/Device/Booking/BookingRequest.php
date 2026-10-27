<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Booking;

use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Offline\ActionTime;

/**
 * Zusatzangaben einer Buchung (POST devices/{id}/state): Kennung vom Handy, Aktionszeit (nur
 * Offline-Buchungen, dann immer mit Kennung) und ob die Buchung als alte Buchung nach Rückfrage
 * gesendet wurde. Die Zeitgrenzen prüft der Handler vorher.
 */
final readonly class BookingRequest
{
    /**
     * @param string $requestHash SHA-256 (binär) über den Inhalt der Buchung, siehe hash()
     */
    public function __construct(
        public ?ClientRef $clientRef,
        public ?ActionTime $occurredAt,
        public bool $staleConfirmed,
        public string $requestHash,
    ) {
        if ($occurredAt !== null && $clientRef === null) {
            throw new \LogicException('Eine Offline-Buchung braucht eine client_ref.');
        }
        if ($staleConfirmed && $occurredAt === null) {
            throw new \LogicException('Nur Offline-Buchungen können alt sein.');
        }
    }

    public function isOffline(): bool
    {
        return $this->occurredAt !== null;
    }

    /**
     * Inhalt einer Buchung für den Vergleich bei Wiederholung: Gerät, Version, gewünschter Standort
     * und Status, Kommentar, Aktionszeit (auf Sekunden). confirmed_stale zählt nicht (eine
     * Wiederholung nach Rückfrage ist dieselbe Buchung).
     */
    public static function hash(int $deviceId, int $version, DeviceStateChange $change, ?string $comment, ?ActionTime $occurredAt): string
    {
        return hash('sha256', json_encode(
            [$deviceId, $version, $change->locationId, $change->status?->value, $comment, $occurredAt?->sql()],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ), true);
    }
}
