<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Stammdaten eines Geräts, die einzeln geändert und protokolliert werden (Ereignis field_changed,
 * Werte wie chk_fe_device_events_field). Standort und Status gehören nicht dazu (state_changed),
 * ebenso das Prüfdatum (due_changed).
 */
enum DeviceField: string
{
    case Identifier = 'identifier';
    case DeviceType = 'device_type';
    case SerialNumber = 'serial_number';
    case Manufacturer = 'manufacturer';
    case Model = 'model';
    case ProcuredOn = 'procured_on';
    case Note = 'note';

    /**
     * Feld im JSON-Body (beim Gerätetyp die ID).
     */
    public function bodyKey(): string
    {
        return $this === self::DeviceType ? 'device_type_id' : $this->value;
    }

    /**
     * Subjekt für Meldungen, z. B. „Die Seriennummer“.
     */
    public function subject(): string
    {
        return match ($this) {
            self::Identifier => 'Die Kennung',
            self::DeviceType => 'Der Gerätetyp',
            self::SerialNumber => 'Die Seriennummer',
            self::Manufacturer => 'Der Hersteller',
            self::Model => 'Das Modell',
            self::ProcuredOn => 'Das Beschaffungsdatum',
            self::Note => 'Die Notiz',
        };
    }
}
