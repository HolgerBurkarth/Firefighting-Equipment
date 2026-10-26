<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

use FirefightingEquipment\Device\DeviceField;

/**
 * Spalten des CSV-Imports, dieselben wie im Geräte-Export (DeviceCsv::DEVICE_HEADER, per Test
 * gesichert). Die Werte sind zugleich die Feldnamen der Änderungen in der Vorschau.
 */
enum ImportColumn: string
{
    case Identifier = 'identifier';
    case DeviceType = 'device_type';
    case Location = 'location';
    case Status = 'status';
    case NextInspectionDue = 'next_inspection_due';
    case SerialNumber = 'serial_number';
    case Manufacturer = 'manufacturer';
    case Model = 'model';
    case ProcuredOn = 'procured_on';
    case Note = 'note';

    /**
     * Überschrift in der Datei (wie im Export).
     */
    public function label(): string
    {
        return match ($this) {
            self::Identifier => 'Kennung',
            self::DeviceType => 'Gerätetyp',
            self::Location => 'Standort',
            self::Status => 'Status',
            self::NextInspectionDue => 'Nächste Prüfung',
            self::SerialNumber => 'Seriennummer',
            self::Manufacturer => 'Hersteller',
            self::Model => 'Modell',
            self::ProcuredOn => 'Beschaffungsdatum',
            self::Note => 'Notiz',
        };
    }

    /**
     * Spalte zu einer Überschrift: Leerraum am Rand und Groß-/Kleinschreibung zählen nicht.
     */
    public static function fromHeader(string $header): ?self
    {
        $header = mb_strtolower(trim($header));
        foreach (self::cases() as $column) {
            if (mb_strtolower($column->label()) === $header) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Stammdatenfeld (Ereignis field_changed); null bei Standort, Status und Prüfdatum.
     */
    public function deviceField(): ?DeviceField
    {
        return match ($this) {
            self::Location, self::Status, self::NextInspectionDue => null,
            default => DeviceField::from($this->value),
        };
    }
}
