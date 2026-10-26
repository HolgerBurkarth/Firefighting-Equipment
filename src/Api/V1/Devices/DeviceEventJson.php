<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\Device\DeviceEvent;
use FirefightingEquipment\Device\DeviceEventLocation;
use FirefightingEquipment\Device\DeviceEventType;

/**
 * JSON-Darstellung eines Eintrags der Historie. Immer alle Schlüssel; location, status,
 * inspection_due und field sind null, wenn die Art des Eintrags sie nicht kennt. Bei created und
 * imported ist "from" jeweils null. Zeit in UTC, Datumsfelder als JJJJ-MM-TT, feste Werte mit
 * deutscher Bezeichnung (type_label, from_label/to_label, field.label).
 */
final class DeviceEventJson
{
    /**
     * @return array<string, mixed>
     */
    public static function event(DeviceEvent $event): array
    {
        $hasLocation = $event->fromLocation !== null || $event->toLocation !== null;
        $hasStatus = $event->fromStatus !== null || $event->toStatus !== null;
        $hasDue = $event->type === DeviceEventType::DueChanged;

        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'type_label' => $event->label(),
            'created_at' => AuthJson::time($event->createdAt),
            'user' => ['id' => $event->userId, 'name' => $event->userName],
            'comment' => $event->comment,
            'location' => $hasLocation
                ? ['from' => self::location($event->fromLocation), 'to' => self::location($event->toLocation)]
                : null,
            'status' => $hasStatus ? [
                'from' => $event->fromStatus?->value,
                'from_label' => $event->fromStatus?->label(),
                'to' => $event->toStatus?->value,
                'to_label' => $event->toStatus?->label(),
            ] : null,
            'inspection_due' => $hasDue ? ['from' => $event->fromDue?->value, 'to' => $event->toDue?->value] : null,
            'field' => $event->field === null ? null : [
                'name' => $event->field->value,
                'label' => $event->field->label(),
                'old' => $event->oldValue,
                'new' => $event->newValue,
            ],
        ];
    }

    /**
     * @return array{id: int, name: string, path: string}|null
     */
    private static function location(?DeviceEventLocation $location): ?array
    {
        return $location === null ? null : ['id' => $location->id, 'name' => $location->name, 'path' => $location->path()];
    }
}
