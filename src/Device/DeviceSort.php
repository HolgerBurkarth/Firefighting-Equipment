<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Sortierung der Geräteliste (und des CSV-Exports), Richtung über SortDirection. Texte werden
 * natürlich sortiert (NATURAL_SORT_KEY, M2 vor M10). Bei Gleichstand immer natürlich nach Kennung,
 * dann nach ID (beides aufsteigend), damit die Reihenfolge für Seiten stabil ist.
 */
enum DeviceSort: string
{
    /** natürlich nach Kennung */
    case Identifier = 'identifier';
    /** natürlich nach Name des Gerätetyps */
    case DeviceType = 'device_type';
    /** natürlich nach dem angezeigten Pfad des Standorts („HLF 20 / G1“) */
    case Location = 'location';
    /** fachlich: einsatzbereit, in Wartung, defekt, ausgesondert */
    case Status = 'status';
    /** nach nächster Prüfung; Geräte ohne Datum in beiden Richtungen am Ende */
    case NextInspectionDue = 'next_inspection_due';
}
