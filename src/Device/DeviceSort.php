<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Sortierung der Geräteliste. Kennungen werden natürlich sortiert (NATURAL_SORT_KEY, M2 vor M10).
 */
enum DeviceSort: string
{
    /** natürlich nach Kennung */
    case Identifier = 'identifier';
    /** nächste Prüfung zuerst, Geräte ohne Datum am Ende; dann natürlich nach Kennung */
    case NextInspectionDue = 'next_inspection_due';
}
