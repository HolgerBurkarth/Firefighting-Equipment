<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\DeviceTypes;

use FirefightingEquipment\Device\DeviceTypeRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * GET api/v1/device-types[?include_inactive=1] (angemeldet): Gerätetypen der Stadt nach Name, je
 * mit der Zahl nicht ausgesonderter Geräte. Deaktivierte nur mit include_inactive=1.
 */
final readonly class DeviceTypeListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $includeInactive = $context->queryFlag('include_inactive');

        $types = new DeviceTypeRepository($context->db->get())->listByTenant($tenant, $includeInactive);

        return new JsonResponse(['device_types' => array_map(DeviceTypeJson::deviceType(...), $types)]);
    }
}
