<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Roles;

use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * GET api/v1/permission-catalog (Super-Admin): Rechte-Katalog mit deutschen Bezeichnungen für den
 * Editor der Rollen (RoleJson::catalog), ohne Datenbank.
 */
final readonly class PermissionCatalogHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        return new JsonResponse(RoleJson::catalog());
    }
}
