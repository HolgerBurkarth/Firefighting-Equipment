<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Eine Seite der Historie einer Stadt (Einträge mit ihrem Gerät) mit der Gesamtzahl aller Einträge.
 */
final readonly class DeviceHistoryPage
{
    /**
     * @param list<DeviceHistoryEntry> $entries
     */
    public function __construct(
        public array $entries,
        public int $total,
    ) {
    }
}
