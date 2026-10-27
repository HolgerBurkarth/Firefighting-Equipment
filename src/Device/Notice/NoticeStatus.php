<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Notice;

/**
 * Status eines Hinweises (Werte wie chk_fe_device_notices_status).
 */
enum NoticeStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'offen',
            self::Resolved => 'erledigt',
        };
    }
}
