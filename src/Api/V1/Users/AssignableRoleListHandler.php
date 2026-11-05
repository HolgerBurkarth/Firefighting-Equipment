<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Users;

use FirefightingEquipment\Api\V1\Roles\RoleJson;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\Role\RoleUsage;

/**
 * GET api/v1/roles (Benutzer: Ansehen; Plan Phase 1, Schritt 1.6): die Rollen der eigenen Stadt für
 * die Auswahl beim Zuweisen, Freischalten und bei Einladungscodes (Admin, Benutzer, dann nach Name).
 * Je Rolle dieselben Felder wie beim Super-Admin (RoleJson::role, mit usage), dazu assignable und
 * assign_blocker: ob das Konto der Sitzung die Rolle vergeben darf (F5) bzw. warum nicht (null = darf).
 * Dazu catalog = GET permission-catalog des Super-Admins (Bezeichnungen für „Sieht: …“).
 */
final readonly class AssignableRoleListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $roles = new RoleRepository($context->db->get());
        $admin = UserActionHandler::administration($context, $tenant);
        $usage = $roles->usage($tenant);

        $list = [];
        foreach ($roles->listByTenant($tenant) as $role) {
            $blocker = $admin->assignBlocker($role);
            $list[] = [
                ...RoleJson::role($role, $usage[$role->id] ?? new RoleUsage(0, 0)),
                'assignable' => $blocker === null,
                'assign_blocker' => $blocker,
            ];
        }

        return new JsonResponse(['roles' => $list, 'catalog' => RoleJson::catalog()]);
    }
}
