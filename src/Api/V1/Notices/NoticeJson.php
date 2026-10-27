<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Notices;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\Device\DeviceEventLocation;
use FirefightingEquipment\Device\Notice\DeviceNotice;
use FirefightingEquipment\Device\Notice\NoticeHistoryEntry;
use FirefightingEquipment\Device\Notice\NoticeUser;

/**
 * JSON-Darstellung eines Hinweises (nur für Admins): Art, Status, Gerät, Buchung und anderer Stand
 * mit Konten (Anzeigename und E-Mail; anonymisiert ohne E-Mail), Verlauf (älteste zuerst). Zeiten in
 * UTC, Standorte mit aktuellem Namen und Pfad.
 */
final class NoticeJson
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function notice(DeviceNotice $notice): array
    {
        return [
            'id' => $notice->id,
            'kind' => $notice->kind->value,
            'kind_label' => $notice->kind->label(),
            'status' => $notice->status->value,
            'status_label' => $notice->status->label(),
            'stale_confirmed' => $notice->staleConfirmed,
            'created_at' => AuthJson::time($notice->createdAt),
            'updated_at' => AuthJson::time($notice->updatedAt),
            'device' => ['id' => $notice->deviceId, 'identifier' => $notice->deviceIdentifier, 'device_type' => $notice->deviceTypeName],
            'booking' => [
                'client_ref' => $notice->clientRef,
                'event_id' => $notice->eventId,
                'user' => self::user($notice->user),
                'occurred_at' => AuthJson::time($notice->occurredAt),
                'received_at' => AuthJson::time($notice->createdAt),
                'location' => self::location($notice->requestedLocation),
                'status' => $notice->requestedStatus?->value,
                'status_label' => $notice->requestedStatus?->label(),
            ],
            'other' => [
                'user' => $notice->otherUser === null ? null : self::user($notice->otherUser),
                'occurred_at' => AuthJson::time($notice->otherOccurredAt),
                'location' => self::location($notice->otherLocation),
                'status' => $notice->otherStatus->value,
                'status_label' => $notice->otherStatus->label(),
            ],
            'history' => array_map(self::history(...), $notice->history),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function history(NoticeHistoryEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'action' => $entry->change->value,
            'action_label' => $entry->change->label(),
            'user' => self::user($entry->user),
            'at' => AuthJson::time($entry->at),
            'note' => $entry->note,
        ];
    }

    /**
     * @return array{id: int, name: string, email: ?string}
     */
    private static function user(NoticeUser $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }

    /**
     * @return array{id: int, name: string, path: string}|null
     */
    private static function location(?DeviceEventLocation $location): ?array
    {
        return $location === null ? null : ['id' => $location->id, 'name' => $location->name, 'path' => $location->path()];
    }
}
