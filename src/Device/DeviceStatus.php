<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Status eines Geräts (Werte wie chk_fe_devices_status). Ausgesonderte Geräte sind aus normalen
 * Listen und Prüffristen ausgeblendet und nicht änderbar, bis ein Admin sie wieder aufnimmt.
 */
enum DeviceStatus: string
{
    case Ready = 'ready';
    case Maintenance = 'maintenance';
    case Defective = 'defective';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'einsatzbereit',
            self::Maintenance => 'in Wartung',
            self::Defective => 'defekt',
            self::Retired => 'ausgesondert',
        };
    }
}
