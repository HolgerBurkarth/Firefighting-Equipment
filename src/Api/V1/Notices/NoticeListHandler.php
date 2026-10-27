<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Notices;

use FirefightingEquipment\Api\V1\Devices\DeviceListHandler;
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\Device\Notice\NoticeStatus;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * GET api/v1/notices[?status=open|resolved|all] (Admin): Hinweise der Stadt, neueste zuerst,
 * seitenweise (limit 1–200, Standard 50; offset). Standard status=open. Antwort {notices, total,
 * open, limit, offset}; open = Anzahl offener Hinweise (unabhängig vom Filter).
 */
final readonly class NoticeListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $status = match ($context->request->query()['status'] ?? 'open') {
            'open' => NoticeStatus::Open,
            'resolved' => NoticeStatus::Resolved,
            'all' => null,
            default => throw HttpException::invalidRequest('Der Parameter "status" muss "open", "resolved" oder "all" sein.'),
        };
        $limit = $context->queryInt('limit', 1, DeviceListHandler::MAX_LIMIT) ?? DeviceListHandler::DEFAULT_LIMIT;
        $offset = $context->queryInt('offset', 0, DeviceListHandler::MAX_OFFSET) ?? 0;

        $notices = new DeviceNoticeRepository($context->db->get());
        $page = $notices->list($tenant, $status, $limit, $offset);

        return new JsonResponse([
            'notices' => array_map(NoticeJson::notice(...), $page->notices),
            'total' => $page->total,
            'open' => $notices->countOpen($tenant),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }
}
