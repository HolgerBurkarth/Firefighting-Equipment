<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\DeviceTypes;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\Device\DeviceType;

/**
 * JSON-Darstellung eines Gerätetyps. Zeiten in UTC.
 */
final class DeviceTypeJson
{
    /**
     * @return array<string, mixed>
     */
    public static function deviceType(DeviceType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name->value,
            'active' => $type->active,
            'device_count' => $type->deviceCount,
            'created_at' => AuthJson::time($type->createdAt),
            'updated_at' => AuthJson::time($type->updatedAt),
        ];
    }
}
