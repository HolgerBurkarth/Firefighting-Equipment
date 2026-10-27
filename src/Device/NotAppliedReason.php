<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Warum eine Offline-Buchung nicht übernommen wurde (nur in der Historie, applied = 0). Werte wie
 * chk_fe_device_events_applied.
 */
enum NotAppliedReason: string
{
    /** Aktionszeit nicht jünger als der aktuelle Stand („der Letzte gewinnt“) */
    case Older = 'older';
    /** Gerät inzwischen ausgesondert */
    case Retired = 'retired';
    /** Ziel-Standort inzwischen gesperrt */
    case LocationLocked = 'location_locked';

    public function label(): string
    {
        return match ($this) {
            self::Older => 'Neuerer Stand vorhanden',
            self::Retired => 'Gerät ausgesondert',
            self::LocationLocked => 'Standort gesperrt',
        };
    }
}
