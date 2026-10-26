<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Eine Seite der Geräteliste mit der Gesamtzahl aller passenden Geräte.
 */
final readonly class DevicePage
{
    /**
     * @param list<Device> $devices
     */
    public function __construct(
        public array $devices,
        public int $total,
    ) {
    }
}
