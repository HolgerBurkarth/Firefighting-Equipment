<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1;

use FirefightingEquipment\Api\V1\Appearance\AppearanceJson;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\TenantAppearanceRepository;

/**
 * GET api/v1/tenant (öffentlich): Kürzel, Name und Erscheinungsbild der Stadt (Akzentfarbe, immer
 * ein Wert; Logo-Adressen oder null; siehe AppearanceJson). Nur unter einer Stadt registriert.
 */
final readonly class TenantHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('TenantHandler braucht eine Stadt.');
        $appearance = new TenantAppearanceRepository($context->db->get())->find($tenant);

        return new JsonResponse(AppearanceJson::tenant($tenant, $appearance));
    }
}
