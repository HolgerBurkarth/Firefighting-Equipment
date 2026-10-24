<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1;

use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * GET api/v1/tenant: Kürzel und Name der Stadt. Nur unter einer Stadt registriert.
 */
final readonly class TenantHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('TenantHandler braucht eine Stadt.');

        return new JsonResponse(['slug' => $tenant->slug->value, 'name' => $tenant->name->value]);
    }
}
