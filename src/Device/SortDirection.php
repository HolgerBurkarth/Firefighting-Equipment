<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Richtung einer Sortierung (Parameter dir).
 */
enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    /** Schlüsselwort für ORDER BY */
    public function sql(): string
    {
        return match ($this) {
            self::Asc => 'ASC',
            self::Desc => 'DESC',
        };
    }
}
