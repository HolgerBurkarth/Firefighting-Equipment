<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

/**
 * Gruppen der Aktionen im Editor der Rollen (Mockup „Rolle bearbeiten“, Abschnitt „2 · Aktionen“),
 * in der Reihenfolge der Anzeige.
 */
enum ActionGroup
{
    case Booking;
    case Devices;
    case Bulk;
    case Notifications;

    public function label(): string
    {
        return match ($this) {
            self::Booking => 'Buchen',
            self::Devices => 'Ausrüstung verwalten',
            self::Bulk => 'Mehrere Ausrüstungen',
            self::Notifications => 'Benachrichtigungen',
        };
    }
}
