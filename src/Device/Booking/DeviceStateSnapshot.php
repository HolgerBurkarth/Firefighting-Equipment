<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Booking;

use FirefightingEquipment\Device\DeviceStatus;

/**
 * Stand eines Geräts (Standort und Status) mit Aktionszeit und dem Konto, das ihn gesetzt hat
 * (letzter übernommener Eintrag created, imported oder state_changed; null, wenn es keinen gibt).
 */
final readonly class DeviceStateSnapshot
{
    public function __construct(
        public int $locationId,
        public DeviceStatus $status,
        /** state_changed_at (UTC) */
        public \DateTimeImmutable $at,
        public ?int $userId,
    ) {
    }
}
