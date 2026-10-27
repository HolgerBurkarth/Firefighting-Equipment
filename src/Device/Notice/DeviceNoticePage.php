<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Notice;

/**
 * Eine Seite der Hinweise mit der Gesamtzahl (zum Filter).
 */
final readonly class DeviceNoticePage
{
    /**
     * @param list<DeviceNotice> $notices
     */
    public function __construct(
        public array $notices,
        public int $total,
    ) {
    }
}
