<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Device\Booking\BookingOutcome;
use FirefightingEquipment\Device\Booking\BookingReason;
use FirefightingEquipment\Device\Booking\BookingResult;

/**
 * Ergebnis einer Buchung für den Kameraden (Feld "result" von POST devices/{id}/state):
 * {outcome, outcome_label, reason, reason_label, message, stale_confirmed, client_ref, replayed,
 * notice_id}. message ist ein deutscher Satz mit dem Ergebnis und ggf. dem Grund.
 */
final class DeviceBookingJson
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function result(BookingResult $result): array
    {
        return [
            'outcome' => $result->outcome->value,
            'outcome_label' => $result->outcome->label(),
            'reason' => $result->reason?->value,
            'reason_label' => $result->reason?->label(),
            'message' => self::message($result),
            'stale_confirmed' => $result->staleConfirmed,
            'client_ref' => $result->clientRef?->value,
            'replayed' => $result->replayed,
            'notice_id' => $result->noticeId,
        ];
    }

    public static function message(BookingResult $result): string
    {
        $device = $result->device;
        $state = sprintf('„%s“ ist jetzt: %s, %s.', $device->identifier->value, $device->locationPath(), $device->status->label());
        $current = sprintf('Aktueller Stand von „%s“: %s, %s.', $device->identifier->value, $device->locationPath(), $device->status->label());

        $message = match (true) {
            $result->outcome === BookingOutcome::Unchanged => 'Keine Änderung: ' . $current,
            $result->reason === BookingReason::Overwritten => 'Übernommen. Ein zwischenzeitlich gebuchter, älterer Stand wurde überschrieben; die Admins wurden informiert. ' . $state,
            $result->outcome === BookingOutcome::Applied => 'Übernommen. ' . $state,
            $result->reason === BookingReason::Older => 'Nur in der Historie: Das Gerät wurde nach Ihrer Buchung bereits anders gebucht. ' . $current,
            $result->reason === BookingReason::Retired => 'Nicht übernommen: Das Gerät wurde inzwischen ausgesondert. Ihre Buchung steht nur in der Historie. ' . $current,
            $result->reason === BookingReason::LocationLocked => 'Nicht übernommen: Der Ziel-Standort ist inzwischen gesperrt. Ihre Buchung steht nur in der Historie. ' . $current,
            default => throw new \LogicException('Ergebnis ohne Grund.'),
        };
        if ($result->staleConfirmed) {
            $message .= ' Die Buchung war älter als erlaubt und wurde nach Rückfrage gesendet; die Admins wurden informiert.';
        }

        return $message;
    }
}
