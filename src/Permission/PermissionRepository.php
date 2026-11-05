<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

use FirefightingEquipment\Role\SystemRole;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Rechte eines Kontos aus seiner Rolle, einmal je Anfrage (SessionManager). Rolle Admin: eine
 * Abfrage, sonst zwei, je eingeschaltetem Datenfilter eine weitere. Ohne Rolle (darf es seit
 * Migration 0037 nicht geben) keine Rechte.
 */
final readonly class PermissionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function forUser(Tenant $tenant, int $userId): Permissions
    {
        return $this->forTenantUser($tenant->id, $userId);
    }

    /**
     * Wie forUser(), mit der ID der Stadt (z. B. beim Anmelden, die Stadt des Kontos).
     */
    public function forTenantUser(int $tenantId, int $userId): Permissions
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.system_key, r.version, r.filter_locations, r.filter_device_types FROM fe_users u'
            . ' JOIN fe_roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id WHERE u.id = ? AND u.tenant_id = ?',
        );
        $stmt->execute([$userId, $tenantId]);
        $role = $stmt->fetch();
        if (!is_array($role)) {
            return Permissions::none();
        }
        $roleId = (int) $role['id'];
        if ($role['system_key'] === SystemRole::Admin->value) {
            // CHECK chk_fe_roles_admin: die Rolle Admin nie mit Datenfilter.
            return Permissions::all()->ofRole($roleId, (int) $role['version'], DeviceVisibility::all());
        }

        $rows = $this->pdo->prepare(
            "SELECT 'area' AS kind, area AS name, level FROM fe_role_areas WHERE role_id = ? AND tenant_id = ?"
            . " UNION ALL SELECT 'action', action, NULL FROM fe_role_actions WHERE role_id = ? AND tenant_id = ?",
        );
        $rows->execute([$roleId, $tenantId, $roleId, $tenantId]);

        $areas = [];
        $actions = [];
        foreach ($rows->fetchAll() as $row) {
            if ($row['kind'] === 'area') {
                $areas[(string) $row['name']] = (string) $row['level'];
            } else {
                $actions[] = (string) $row['name'];
            }
        }

        return Permissions::fromRows($areas, $actions)->ofRole($roleId, (int) $role['version'], $this->visibility(
            $tenantId,
            $roleId,
            (int) $role['filter_locations'] === 1,
            (int) $role['filter_device_types'] === 1,
        ));
    }

    /**
     * Datenfilter der Rolle: Standorte (ein Standort der Ebene 1 schließt seine Fächer ein, auch
     * später angelegte) und Gerätetypen; eingeschaltet ohne Auswahl = nichts.
     */
    private function visibility(int $tenantId, int $roleId, bool $filterLocations, bool $filterDeviceTypes): DeviceVisibility
    {
        if (!$filterLocations && !$filterDeviceTypes) {
            return DeviceVisibility::all();
        }
        $locations = null;
        if ($filterLocations) {
            $stmt = $this->pdo->prepare(
                'SELECT DISTINCT l.id FROM fe_role_locations rl JOIN fe_locations l ON l.tenant_id = rl.tenant_id'
                . ' AND (l.id = rl.location_id OR l.parent_id = rl.location_id) WHERE rl.role_id = ? AND rl.tenant_id = ?',
            );
            $stmt->execute([$roleId, $tenantId]);
            $locations = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
        $types = null;
        if ($filterDeviceTypes) {
            $stmt = $this->pdo->prepare('SELECT device_type_id FROM fe_role_device_types WHERE role_id = ? AND tenant_id = ?');
            $stmt->execute([$roleId, $tenantId]);
            $types = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }

        return DeviceVisibility::restricted($locations, $types);
    }

    /**
     * SQL-Bedingung „die Rolle des Kontos kann die Aktion“ wie Permissions::can (Rolle Admin alle,
     * sonst die Aktion und der Bereich, den sie braucht), für Abfragen über viele Konten (z. B.
     * Empfänger der Log-Mails). Super-Admins haben keine Rolle und erfüllen sie nie.
     *
     * @param string $user Alias von fe_users in der Abfrage (nur [a-z_])
     *
     * @return array{string, list<string>} Bedingung und ihre Parameter
     */
    public static function grantsActionSql(string $user, Action $action): array
    {
        if (preg_match('/\A[a-z_]+\z/', $user) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Alias.');
        }
        $condition = 'EXISTS (SELECT 1 FROM fe_roles pr WHERE pr.id = ' . $user . '.role_id AND pr.tenant_id = ' . $user . '.tenant_id'
            . ' AND (pr.system_key = ? OR (EXISTS (SELECT 1 FROM fe_role_actions pa WHERE pa.role_id = pr.id'
            . ' AND pa.tenant_id = pr.tenant_id AND pa.action = ?)';
        $params = [SystemRole::Admin->value, $action->value];
        $required = $action->requires();
        if ($required !== null) {
            $levels = array_values(array_filter(AreaLevel::cases(), static fn (AreaLevel $l): bool => $l->includes($required[1])));
            $condition .= ' AND EXISTS (SELECT 1 FROM fe_role_areas pb WHERE pb.role_id = pr.id AND pb.tenant_id = pr.tenant_id'
                . ' AND pb.area = ? AND pb.level IN (' . implode(', ', array_fill(0, count($levels), '?')) . '))';
            $params[] = $required[0]->value;
            foreach ($levels as $level) {
                $params[] = $level->value;
            }
        }

        return [$condition . ')))', $params];
    }
}
