<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Locations;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\Device\Location;

/**
 * JSON-Darstellung eines Standorts. Ebene 1 zusätzlich mit device_count_total (einschließlich der
 * Unterstandorte) und children; Unterstandorte ohne diese Felder. Zeiten in UTC.
 */
final class LocationJson
{
    /**
     * @return array<string, mixed>
     */
    public static function location(Location $location): array
    {
        $json = [
            'id' => $location->id,
            'name' => $location->name->value,
            'kind' => $location->kind->value,
            'kind_label' => $location->kind->label(),
            'level' => $location->level(),
            'parent_id' => $location->parentId,
            'active' => $location->active,
            'device_count' => $location->deviceCount,
        ];
        if ($location->level() === 1) {
            $json['device_count_total'] = $location->deviceCountTotal;
            $json['children'] = array_map(self::location(...), $location->children);
        }

        return [
            ...$json,
            'created_at' => AuthJson::time($location->createdAt),
            'updated_at' => AuthJson::time($location->updatedAt),
        ];
    }
}
