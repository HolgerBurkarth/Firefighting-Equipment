<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Tenants;

use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\TenantRepository;

/**
 * GET api/v1/tenants (Super-Admin): alle Städte nach Kürzel, mit Status und Kontenzahlen.
 */
final readonly class TenantListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $summaries = new TenantRepository($context->db->get())->summaries();

        return new JsonResponse(['tenants' => array_map(TenantJson::tenant(...), $summaries)]);
    }
}
