<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Locations;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\Device\Location;

/**
 * JSON-Darstellung eines Standorts. Ebene 1 zusätzlich mit device_count_total (einschließlich der
 * Unterstandorte) und children; Unterstandorte ohne diese Felder. Zeiten in UTC. Gerätezahlen nur
 * sichtbarer Geräte; selectable false (Datenfilter: nur Fächer sichtbar) mit device_count null.
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
            // Nicht wählbar (Datenfilter, nur Fächer sichtbar): ohne Gerätezahl.
            'selectable' => $location->selectable,
            // Aufgabe (Phase 2, P2.2): null = ohne Aufgabe.
            'task' => $location->task?->value,
            'is_default_store' => $location->isDefaultStore,
            'collection_accepts' => $location->collectionAccepts,
            'device_count' => $location->selectable ? $location->deviceCount : null,
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
