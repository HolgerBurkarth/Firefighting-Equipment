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
 * deutscher Bezeichnung (type_label, from_label/to_label, field.label). occurred_at = Aktionszeit
 * (Offline-Buchung: auf dem Handy, sonst wie received_at), received_at = created_at = Eingang beim
 * Server. applied = false: nicht übernommen, nur Historie (Grund in not_applied_reason/-label).
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
            'occurred_at' => AuthJson::time($event->occurredAt()),
            'received_at' => AuthJson::time($event->createdAt),
            'applied' => $event->applied,
            'not_applied_reason' => $event->notAppliedReason?->value,
            'not_applied_label' => $event->notAppliedReason?->label(),
            'client_ref' => $event->clientRef,
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
