<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceEvent;
use FirefightingEquipment\Device\DeviceEventLocation;
use FirefightingEquipment\Device\DeviceField;
use FirefightingEquipment\Device\DeviceHistoryEntry;
use FirefightingEquipment\Time\GermanFormat;

/**
 * Spalten der CSV-Exporte (Format siehe CsvFormat): deutsche Überschriften und Bezeichnungen,
 * Datum TT.MM.JJJJ, Zeitpunkte in deutscher Zeit als TT.MM.JJJJ HH:MM, leere Werte als leeres Feld.
 *
 * Die Geräteliste ist so aufgebaut, dass der CSV-Import sie wieder einlesen kann: Kennung als
 * Schlüssel, Gerätetyp über den Namen, Standort über den vollständigen Pfad, Status über die
 * deutsche Bezeichnung. Spalten nur zusammen mit dem Import ändern.
 */
final class DeviceCsv
{
    public const array DEVICE_HEADER = [
        'Kennung', 'Scan-Code', 'Gerätetyp', 'Standort', 'Status', 'Nächste Prüfung', 'Seriennummer',
        'Hersteller', 'Modell', 'Beschaffungsdatum', 'Notiz',
    ];

    public const array EVENT_HEADER = [
        'Aktionszeit', 'Eingang beim Server', 'Geräte-Nr.', 'Kennung', 'Gerätetyp', 'Ereignis', 'Wer',
        'Standort von', 'Standort nach', 'Status von', 'Status nach', 'Prüfdatum alt', 'Prüfdatum neu',
        'Feld', 'Alter Wert', 'Neuer Wert', 'Kommentar', 'Übernommen', 'Grund (nicht übernommen)',
        'Buchungs-Kennung',
    ];

    /**
     * @return list<string|null>
     */
    public static function device(Device $device): array
    {
        return [
            $device->identifier->value,
            $device->scanCode?->value,
            $device->deviceTypeName,
            $device->locationPath(),
            $device->status->label(),
            self::date($device->nextInspectionDue),
            $device->serialNumber,
            $device->manufacturer,
            $device->model,
            self::date($device->procuredOn),
            $device->note,
        ];
    }

    /**
     * Kennung und Gerätetyp sind der aktuelle Stand des Geräts, Standorte erscheinen mit ihrem
     * aktuellen Pfad (wie in GET devices/{id}/events).
     *
     * @return list<string|null>
     */
    public static function event(DeviceHistoryEntry $entry): array
    {
        $event = $entry->event;

        return [
            GermanFormat::dateTime($event->occurredAt()),
            GermanFormat::dateTime($event->createdAt),
            (string) $entry->deviceId,
            $entry->identifier,
            $entry->deviceTypeName,
            $event->label(),
            $event->userName,
            self::location($event->fromLocation),
            self::location($event->toLocation),
            $event->fromStatus?->label(),
            $event->toStatus?->label(),
            self::date($event->fromDue),
            self::date($event->toDue),
            $event->field?->label(),
            self::fieldValue($event, $event->oldValue),
            self::fieldValue($event, $event->newValue),
            $event->comment,
            $event->applied ? 'Ja' : 'Nein',
            $event->notAppliedReason?->label(),
            $event->clientRef,
        ];
    }

    private static function date(?DeviceDate $date): ?string
    {
        return $date === null ? null : GermanFormat::date($date->value);
    }

    private static function location(?DeviceEventLocation $location): ?string
    {
        return $location?->path();
    }

    /**
     * Werte des Beschaffungsdatums stehen in der Historie als JJJJ-MM-TT.
     */
    private static function fieldValue(DeviceEvent $event, ?string $value): ?string
    {
        return $value !== null && $event->field === DeviceField::ProcuredOn ? GermanFormat::date($value) : $value;
    }
}
