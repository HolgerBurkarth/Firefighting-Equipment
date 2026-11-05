<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

use FirefightingEquipment\Role\SystemRole;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Rechte eines Kontos aus seiner Rolle, einmal je Anfrage (Application). Rolle Admin: eine
 * Abfrage, sonst zwei. Ohne Rolle (darf es seit Migration 0037 nicht geben) keine Rechte.
 */
final readonly class PermissionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function forUser(Tenant $tenant, int $userId): Permissions
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.system_key FROM fe_users u JOIN fe_roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id'
            . ' WHERE u.id = ? AND u.tenant_id = ?',
        );
        $stmt->execute([$userId, $tenant->id]);
        $role = $stmt->fetch();
        if (!is_array($role)) {
            return Permissions::none();
        }
        if ($role['system_key'] === SystemRole::Admin->value) {
            return Permissions::all();
        }

        $rows = $this->pdo->prepare(
            "SELECT 'area' AS kind, area AS name, level FROM fe_role_areas WHERE role_id = ? AND tenant_id = ?"
            . " UNION ALL SELECT 'action', action, NULL FROM fe_role_actions WHERE role_id = ? AND tenant_id = ?",
        );
        $rows->execute([$role['id'], $tenant->id, $role['id'], $tenant->id]);

        $areas = [];
        $actions = [];
        foreach ($rows->fetchAll() as $row) {
            if ($row['kind'] === 'area') {
                $areas[(string) $row['name']] = (string) $row['level'];
            } else {
                $actions[] = (string) $row['name'];
            }
        }

        return Permissions::fromRows($areas, $actions);
    }
}
