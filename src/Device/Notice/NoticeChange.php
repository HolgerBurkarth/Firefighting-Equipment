<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Notice;

/**
 * Eintrag im Verlauf eines Hinweises (Werte wie chk_fe_device_notice_actions_action).
 */
enum NoticeChange: string
{
    case Resolve = 'resolve';
    case Reopen = 'reopen';

    public function label(): string
    {
        return match ($this) {
            self::Resolve => 'Erledigt',
            self::Reopen => 'Wieder geöffnet',
        };
    }

    /** Status nach dieser Änderung */
    public function status(): NoticeStatus
    {
        return match ($this) {
            self::Resolve => NoticeStatus::Resolved,
            self::Reopen => NoticeStatus::Open,
        };
    }
}
