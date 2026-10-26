<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Art eines Eintrags der Historie (Werte wie chk_fe_device_events_type). Die Bezeichnung von
 * state_changed hängt vom Inhalt ab, siehe DeviceEvent::label().
 */
enum DeviceEventType: string
{
    case Created = 'created';
    case Imported = 'imported';
    case StateChanged = 'state_changed';
    case DueChanged = 'due_changed';
    case FieldChanged = 'field_changed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Angelegt',
            self::Imported => 'Importiert',
            self::StateChanged => 'Standort/Status geändert',
            self::DueChanged => 'Prüfdatum geändert',
            self::FieldChanged => 'Stammdaten geändert',
        };
    }
}
