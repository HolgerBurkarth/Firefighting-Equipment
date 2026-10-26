<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Device\DeviceEventRepository;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * GET api/v1/devices/{id}/events (angemeldet): Historie eines Geräts (auch ausgesondert), neueste
 * zuerst, seitenweise; Antwort {events, total, limit, offset}, Einträge siehe DeviceEventJson.
 * Parameter limit (1–200, Standard 50) und offset (Standard 0) wie bei GET devices. Fremdes oder
 * unbekanntes Gerät 404.
 */
final readonly class DeviceEventListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $limit = $context->queryInt('limit', 1, DeviceListHandler::MAX_LIMIT) ?? DeviceListHandler::DEFAULT_LIMIT;
        $offset = $context->queryInt('offset', 0, DeviceListHandler::MAX_OFFSET) ?? 0;

        $pdo = $context->db->get();
        $id = $context->param('id');
        if (new DeviceRepository($pdo)->find($tenant, $id) === null) {
            throw HttpException::notFound();
        }
        $page = new DeviceEventRepository($pdo)->listForDevice($tenant, $id, $limit, $offset);

        return new JsonResponse([
            'events' => array_map(DeviceEventJson::event(...), $page->events),
            'total' => $page->total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }
}
