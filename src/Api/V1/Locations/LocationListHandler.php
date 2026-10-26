<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Locations;

use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * GET api/v1/locations[?include_inactive=1] (angemeldet): Baumstruktur der Standorte (Ebene 1 mit
 * ihren Unterstandorten, jeweils nach Name), je mit der Zahl nicht ausgesonderter Geräte. Gesperrte
 * nur mit include_inactive=1.
 */
final readonly class LocationListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $includeInactive = $context->queryFlag('include_inactive');

        $tree = new LocationRepository($context->db->get())->tree($tenant, $includeInactive);

        return new JsonResponse(['locations' => array_map(LocationJson::location(...), $tree)]);
    }
}
