<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\Device\Device;

/**
 * JSON-Darstellung eines Geräts: summary() für die Liste, device() mit allen Feldern. Datumsfelder
 * als JJJJ-MM-TT, Zeiten in UTC, Status mit deutscher Bezeichnung (status_label).
 */
final class DeviceJson
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Device $device): array
    {
        return [
            'id' => $device->id,
            'identifier' => $device->identifier->value,
            'device_type' => ['id' => $device->deviceTypeId, 'name' => $device->deviceTypeName],
            'location' => ['id' => $device->locationId, 'name' => $device->locationName, 'path' => $device->locationPath()],
            'status' => $device->status->value,
            'status_label' => $device->status->label(),
            'next_inspection_due' => $device->nextInspectionDue?->value,
            'version' => $device->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function device(Device $device): array
    {
        return [
            ...self::summary($device),
            'serial_number' => $device->serialNumber,
            'manufacturer' => $device->manufacturer,
            'model' => $device->model,
            'procured_on' => $device->procuredOn?->value,
            'note' => $device->note,
            'created_at' => AuthJson::time($device->createdAt),
            'updated_at' => AuthJson::time($device->updatedAt),
        ];
    }
}
