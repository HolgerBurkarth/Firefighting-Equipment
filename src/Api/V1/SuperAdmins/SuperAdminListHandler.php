<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\SuperAdmins;

use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\User\UserRepository;

/**
 * GET api/v1/superadmins (Super-Admin): alle Super-Admins nach ID, mit "locked" und "protected".
 */
final readonly class SuperAdminListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $users = new UserRepository($context->db->get())->listSuperAdmins();

        return new JsonResponse(['super_admins' => array_map(SuperAdminJson::superAdmin(...), $users)]);
    }
}
