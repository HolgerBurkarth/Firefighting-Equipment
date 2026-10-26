<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Filter der Geräteliste. Unbekannte oder fremde IDs ergeben eine leere Liste.
 *
 * Status: Mit $statuses nur diese (auch retired, wenn angegeben); ohne $statuses alle außer
 * ausgesonderten, mit $includeRetired alle.
 */
final readonly class DeviceFilter
{
    /** @var list<DeviceStatus> */
    public array $statuses;

    /**
     * @param list<DeviceStatus> $statuses
     * @param string|null        $search   Teil von Kennung, Seriennummer, Hersteller oder Modell
     *                                     (ohne Groß- und Kleinschreibung; geprüft über
     *                                     DeviceText::search())
     *
     * @throws InvalidDeviceValueException Unterstandorte ohne Standort, Suchtext ungültig
     */
    public function __construct(
        public ?int $deviceTypeId = null,
        public ?int $locationId = null,
        /** zusätzlich Geräte in den Unterstandorten von $locationId */
        public bool $includeSublocations = false,
        array $statuses = [],
        public ?string $search = null,
        public bool $includeRetired = false,
    ) {
        if ($includeSublocations && $locationId === null) {
            throw new InvalidDeviceValueException('Unterstandorte lassen sich nur zusammen mit einem Standort (location_id) einschließen.');
        }
        $this->statuses = array_values(array_unique($statuses, SORT_REGULAR));
    }
}
