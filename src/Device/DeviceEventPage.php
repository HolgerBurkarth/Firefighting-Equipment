<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Eine Seite der Historie eines Geräts mit der Gesamtzahl aller Einträge.
 */
final readonly class DeviceEventPage
{
    /**
     * @param list<DeviceEvent> $events
     */
    public function __construct(
        public array $events,
        public int $total,
    ) {
    }
}
