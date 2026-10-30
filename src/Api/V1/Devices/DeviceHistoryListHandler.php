<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Device\DeviceEventRepository;
use FirefightingEquipment\Device\DeviceHistoryEntry;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * GET api/v1/device-events (Admin): Historie der ganzen Stadt (alle Geräte, auch ausgesonderte),
 * neueste zuerst, seitenweise; Antwort {events, total, limit, offset}. Je Eintrag wie
 * DeviceEventJson::event, dazu device {id, identifier, device_type {id, name}} mit dem aktuellen
 * Stand des Geräts. Parameter limit (1–200, Standard 50) und offset (Standard 0) wie bei
 * GET devices/{id}/events. Für die Startseite der Admin-Konsole („Letzte Aktivitäten“).
 */
final readonly class DeviceHistoryListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $limit = $context->queryInt('limit', 1, DeviceListHandler::MAX_LIMIT) ?? DeviceListHandler::DEFAULT_LIMIT;
        $offset = $context->queryInt('offset', 0, DeviceListHandler::MAX_OFFSET) ?? 0;

        $page = new DeviceEventRepository($context->db->get())->listForTenant($tenant, $limit, $offset);

        return new JsonResponse([
            'events' => array_map(self::entry(...), $page->entries),
            'total' => $page->total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(DeviceHistoryEntry $entry): array
    {
        return [
            ...DeviceEventJson::event($entry->event),
            'device' => [
                'id' => $entry->deviceId,
                'identifier' => $entry->identifier,
                'device_type' => ['id' => $entry->deviceTypeId, 'name' => $entry->deviceTypeName],
            ],
        ];
    }
}
