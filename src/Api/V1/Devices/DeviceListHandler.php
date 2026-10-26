<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceFilter;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceSort;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\DeviceText;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * GET api/v1/devices (angemeldet): Geräte der Stadt seitenweise, Antwort {devices, total, limit,
 * offset}.
 *
 * Parameter (alle optional): device_type_id, location_id, include_sublocations=1 (nur mit
 * location_id), status=ready,defective (kommagetrennt; retired schließt ausgesonderte ein), q
 * (Teil von Kennung, Seriennummer, Hersteller oder Modell), include_retired=1 (ohne status: alle
 * Status), sort=identifier|next_inspection_due, limit (1–200, Standard 50), offset (Standard 0).
 * Unbekannte oder fremde IDs ergeben eine leere Liste; ungültige Werte 400.
 */
final readonly class DeviceListHandler implements RequestHandler
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;
    public const int MAX_OFFSET = 1_000_000;

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $query = $context->request->query();
        $filter = self::filter($context);
        $sort = DeviceSort::tryFrom($query['sort'] ?? DeviceSort::Identifier->value) ?? throw HttpException::invalidRequest(sprintf(
            'Der Parameter "sort" muss einer der Werte %s sein.',
            implode(', ', array_map(static fn (DeviceSort $s): string => sprintf('"%s"', $s->value), DeviceSort::cases())),
        ));
        $limit = $context->queryInt('limit', 1, self::MAX_LIMIT) ?? self::DEFAULT_LIMIT;
        $offset = $context->queryInt('offset', 0, self::MAX_OFFSET) ?? 0;

        $page = new DeviceRepository($context->db->get())->list($tenant, $filter, $sort, $limit, $offset);

        return new JsonResponse([
            'devices' => array_map(DeviceJson::summary(...), $page->devices),
            'total' => $page->total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Filter aus den Parametern device_type_id, location_id, include_sublocations, status, q und
     * include_retired (auch für den CSV-Export, DeviceExportHandler).
     *
     * @throws HttpException 400 invalid_request
     */
    public static function filter(RequestContext $context): DeviceFilter
    {
        $query = $context->request->query();
        try {
            return new DeviceFilter(
                $context->queryInt('device_type_id', 1, DeviceFields::MAX_ID),
                $context->queryInt('location_id', 1, DeviceFields::MAX_ID),
                $context->queryFlag('include_sublocations'),
                self::statuses($query['status'] ?? null),
                DeviceText::search($query['q'] ?? null),
                $context->queryFlag('include_retired'),
            );
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }
    }

    /**
     * @return list<DeviceStatus>
     *
     * @throws HttpException 400 invalid_request
     */
    private static function statuses(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        return array_map(static fn (string $s): DeviceStatus => DeviceStatus::tryFrom($s) ?? throw HttpException::invalidRequest(sprintf(
            'Der Parameter "status" muss eine kommagetrennte Liste aus %s sein.',
            implode(', ', array_map(static fn (DeviceStatus $s): string => sprintf('"%s"', $s->value), DeviceStatus::cases())),
        )), explode(',', $value));
    }
}
