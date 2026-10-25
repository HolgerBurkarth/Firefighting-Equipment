<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Tenants;

use FirefightingEquipment\Api\V1\Users\UserJson;
use FirefightingEquipment\Api\V1\Users\UserListHandler;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\TenantAdministration;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\User\UserRepository;

/**
 * GET api/v1/tenants/{id}/users[?status=…] (Super-Admin, nur lesend): Konten einer Stadt nach ID,
 * Filter wie GET users unter der Stadt (ohne Filter alle außer gelöschten). Ändert nichts, auch
 * kein Aufräumen unbestätigter Registrierungen. Unbekannte Stadt 404.
 */
final readonly class TenantUserListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $id = $context->param('id');
        $status = UserListHandler::statusFilter($context);

        $pdo = $context->db->get();
        $admin = new TenantAdministration(new TenantRepository($pdo), new UserRepository($pdo));
        try {
            $tenant = $admin->find($id);
        } catch (TenantNotFoundException) {
            throw HttpException::notFound();
        }

        return new JsonResponse(['users' => array_map(UserJson::user(...), $admin->users($tenant, $status))]);
    }
}
