<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Art eines Standorts (Werte wie chk_fe_locations_kind). Ein Unterstandort hat immer die Art seines
 * übergeordneten Standorts.
 */
enum LocationKind: string
{
    case Vehicle = 'vehicle';
    case Room = 'room';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Vehicle => 'Fahrzeug',
            self::Room => 'Raum/Lager',
            self::External => 'Extern',
        };
    }
}
