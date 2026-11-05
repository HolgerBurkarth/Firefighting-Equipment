<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Zugriff auf fe_roles samt Bereichen, Aktionen und Datenfiltern, immer gefiltert nach Stadt.
 * Bisher nur Lesen und die Standard-Rollen neuer Städte; Anlegen, Ändern und Löschen von Rollen
 * folgen mit der Verwaltung durch den Super-Admin (Plan Phase 1, Schritt 1.5).
 */
final readonly class RoleRepository
{
    private const string COLUMNS = 'id, tenant_id, system_key, name, description, restrict_single_use,'
        . ' filter_locations, filter_device_types, version, created_at, updated_at';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Legt die Standard-Rollen Admin und Benutzer (DefaultRoles) an. Läuft in einer eigenen
     * Transaktion oder, falls schon eine offen ist, in dieser (TenantRepository::create).
     */
    public function createDefaults(Tenant $tenant): void
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->insertRole($tenant, SystemRole::Admin, DefaultRoles::ADMIN_NAME, DefaultRoles::ADMIN_DESCRIPTION);
            $userRoleId = $this->insertRole($tenant, SystemRole::User, DefaultRoles::USER_NAME, DefaultRoles::USER_DESCRIPTION);

            $insertArea = $this->pdo->prepare('INSERT INTO fe_role_areas (role_id, tenant_id, area, level) VALUES (?, ?, ?, ?)');
            foreach (DefaultRoles::USER_AREAS as $area => $level) {
                $insertArea->execute([$userRoleId, $tenant->id, $area, $level]);
            }
            $insertAction = $this->pdo->prepare('INSERT INTO fe_role_actions (role_id, tenant_id, action) VALUES (?, ?, ?)');
            foreach (DefaultRoles::USER_ACTIONS as $action) {
                $insertAction->execute([$userRoleId, $tenant->id, $action]);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<Role> Admin, Benutzer, dann die übrigen nach Name
     */
    public function listByTenant(Tenant $tenant): array
    {
        return $this->fetch($tenant, '', []);
    }

    public function find(Tenant $tenant, int $id): ?Role
    {
        return $this->fetch($tenant, ' AND id = ?', [$id])[0] ?? null;
    }

    public function findSystemRole(Tenant $tenant, SystemRole $systemRole): ?Role
    {
        return $this->fetch($tenant, ' AND system_key = ?', [$systemRole->value])[0] ?? null;
    }

    private function insertRole(Tenant $tenant, SystemRole $systemRole, string $name, string $description): int
    {
        $this->pdo->prepare(
            'INSERT INTO fe_roles (tenant_id, system_key, name, description, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        )->execute([$tenant->id, $systemRole->value, $name, $description]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param string           $where SQL aus diesem Code, nie aus Eingaben
     * @param list<int|string> $params
     *
     * @return list<Role>
     */
    private function fetch(Tenant $tenant, string $where, array $params): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fe_roles WHERE tenant_id = ?' . $where
            . " ORDER BY CASE system_key WHEN 'admin' THEN 0 WHEN 'user' THEN 1 ELSE 2 END, name, id",
        );
        $stmt->execute([$tenant->id, ...$params]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $areas = $this->children($tenant, $ids, 'SELECT role_id, area, level FROM fe_role_areas', 'area');
        $actions = $this->children($tenant, $ids, 'SELECT role_id, action FROM fe_role_actions', 'action');
        $locations = $this->children($tenant, $ids, 'SELECT role_id, location_id FROM fe_role_locations', 'location_id');
        $deviceTypes = $this->children($tenant, $ids, 'SELECT role_id, device_type_id FROM fe_role_device_types', 'device_type_id');

        return array_map(
            static function (array $row) use ($areas, $actions, $locations, $deviceTypes): Role {
                $id = (int) $row['id'];
                $roleAreas = [];
                foreach ($areas[$id] ?? [] as $area) {
                    /** @var 'read'|'write' $level */
                    $level = (string) $area['level'];
                    $roleAreas[(string) $area['area']] = $level;
                }

                return new Role(
                    $id,
                    (int) $row['tenant_id'],
                    $row['system_key'] === null ? null : SystemRole::from((string) $row['system_key']),
                    (string) $row['name'],
                    $row['description'] === null ? null : (string) $row['description'],
                    (int) $row['restrict_single_use'] === 1,
                    (int) $row['filter_locations'] === 1,
                    (int) $row['filter_device_types'] === 1,
                    $roleAreas,
                    array_map(static fn (array $r): string => (string) $r['action'], $actions[$id] ?? []),
                    array_map(static fn (array $r): int => (int) $r['location_id'], $locations[$id] ?? []),
                    array_map(static fn (array $r): int => (int) $r['device_type_id'], $deviceTypes[$id] ?? []),
                    (int) $row['version'],
                    self::utc((string) $row['created_at']),
                    self::utc((string) $row['updated_at']),
                );
            },
            $rows,
        );
    }

    /**
     * Zeilen einer Untertabelle je Rolle, nach $orderColumn sortiert (bei Texten binär).
     *
     * @param list<int> $roleIds
     * @param string    $select  SQL aus diesem Code, nie aus Eingaben
     *
     * @return array<int, list<array<string, mixed>>> Rollen-ID → Zeilen
     */
    private function children(Tenant $tenant, array $roleIds, string $select, string $orderColumn): array
    {
        $stmt = $this->pdo->prepare(
            $select . ' WHERE tenant_id = ? AND role_id IN (' . implode(', ', array_fill(0, count($roleIds), '?')) . ')'
            . ' ORDER BY role_id, ' . $orderColumn,
        );
        $stmt->execute([$tenant->id, ...$roleIds]);

        $byRole = [];
        foreach ($stmt->fetchAll() as $row) {
            $byRole[(int) $row['role_id']][] = $row;
        }

        return $byRole;
    }

    private static function utc(string $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'))
            ?: throw new \UnexpectedValueException(sprintf('Ungültiger Zeitstempel: %s', $value));
    }
}
