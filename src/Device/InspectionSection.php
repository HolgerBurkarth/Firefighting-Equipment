<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Abschnitt der Übersicht der Prüffristen (InspectionWindow). Ausgesonderte Geräte gehören nie dazu.
 */
enum InspectionSection: string
{
    /** Prüfdatum vor heute; am stärksten überfällig zuerst */
    case Overdue = 'overdue';
    /** Prüfdatum von heute bis heute + Tage; nächste Fälligkeit zuerst */
    case DueSoon = 'due_soon';
    /** kein Prüfdatum; nach Kennung */
    case WithoutDate = 'without_date';
}
