<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Notice;

use FirefightingEquipment\Device\Booking\DeviceStateSnapshot;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Offline\ActionTime;

/**
 * Neuer Hinweis zu einer Offline-Buchung (DeviceRepository::book, in derselben Transaktion).
 */
final readonly class NewNotice
{
    public function __construct(
        public NoticeKind $kind,
        public bool $staleConfirmed,
        public int $deviceId,
        public int $bookingId,
        public ?int $eventId,
        public int $userId,
        public ActionTime $occurredAt,
        public DeviceStateChange $requested,
        /** Stand beim Eingang der Buchung (bei overwritten der überschriebene) */
        public DeviceStateSnapshot $other,
    ) {
    }
}
