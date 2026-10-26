<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Filter des Exports der Historie einer Stadt. Unbekannte oder fremde IDs ergeben eine leere Liste.
 *
 * Zeitraum halboffen in UTC: $fromUtc <= created_at < $untilUtc (der Aufrufer rechnet Kalendertage
 * deutscher Zeit um, siehe LocalDate::startOfDayUtc). Gerätetyp = aktueller Typ des Geräts.
 * Standort = Von- ODER Nach-Standort des Ereignisses (mit $includeSublocations auch dessen
 * Unterstandorte); Ereignisse ohne Standort (Prüfdatum, Stammdaten) fallen damit heraus.
 */
final readonly class DeviceHistoryFilter
{
    /**
     * @param string $fromUtc  JJJJ-MM-TT HH:MM:SS, einschließlich
     * @param string $untilUtc JJJJ-MM-TT HH:MM:SS, ausschließlich
     *
     * @throws InvalidDeviceValueException Unterstandorte ohne Standort
     */
    public function __construct(
        public string $fromUtc,
        public string $untilUtc,
        public ?int $deviceId = null,
        public ?int $deviceTypeId = null,
        public ?int $locationId = null,
        public bool $includeSublocations = false,
        /** true = neueste zuerst */
        public bool $newestFirst = true,
    ) {
        if ($includeSublocations && $locationId === null) {
            throw new InvalidDeviceValueException('Unterstandorte lassen sich nur zusammen mit einem Standort (location_id) einschließen.');
        }
    }
}
