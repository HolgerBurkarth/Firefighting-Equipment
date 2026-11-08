<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Aufgabe eines Standorts der Ebene 1 (Werte wie chk_fe_locations_task). Ohne Aufgabe verhält sich
 * ein Standort wie bisher. Der Super-Admin schaltet die Aufgaben je Stadt frei (Phase 2, P2.2).
 */
enum LocationTask: string
{
    case Store = 'store';
    case Collection = 'collection';
    case Workshop = 'workshop';
    case Returns = 'returns';
    case Borrower = 'borrower';

    /** Standardname in der Oberfläche (Konzept Abschnitt 9). */
    public function label(): string
    {
        return match ($this) {
            self::Store => 'Lager',
            self::Collection => 'Sammelstelle',
            self::Workshop => 'Werkstatt',
            self::Returns => 'Rückgabe',
            self::Borrower => 'Ausgeliehen an',
        };
    }
}
