<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Zugriff auf fe_roles samt Bereichen, Aktionen und Datenfiltern, immer gefiltert nach Stadt
 * (Ausnahme findInAnyTenant für den Super-Admin). Ändernde Methoden (create, update, delete) öffnen
 * eine eigene Transaktion (eine bereits offene ist ein Fehler; Ausnahme createDefaults).
 *
 * Regeln (Plan Phase 1, Schritt 1.5): Die Rolle Admin ist nicht änderbar und nicht löschbar, die
 * Rolle Benutzer änderbar, aber nicht löschbar. Gelöscht wird nur eine Rolle ohne Konten und ohne
 * offene Einladungscodes. Speichern prüft die Version (gleichzeitiges Bearbeiten) und F3 (keine
 * Verwaltung mit Datenfilter); Standorte und Ausrüstungstypen nur aus derselben Stadt. Jede
 * Änderung erhöht version (damit auch permissions.version der Konten); Sitzungen enden nicht (F1).
 */
final readonly class RoleRepository
{
    private const int ER_DUP_ENTRY = 1062;
    private const int ER_ROW_IS_REFERENCED = 1451;
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
        return $this->fetch($tenant->id, '', []);
    }

    public function find(Tenant $tenant, int $id): ?Role
    {
        return $this->findOfTenant($tenant->id, $id);
    }

    /** Wie find(), mit der ID der Stadt (z. B. die Stadt eines Kontos, UserRepository). */
    public function findOfTenant(int $tenantId, int $id): ?Role
    {
        return $this->fetch($tenantId, ' AND id = ?', [$id])[0] ?? null;
    }

    public function findSystemRole(Tenant $tenant, SystemRole $systemRole): ?Role
    {
        return $this->findSystemRoleOfTenant($tenant->id, $systemRole);
    }

    public function findSystemRoleOfTenant(int $tenantId, SystemRole $systemRole): ?Role
    {
        return $this->fetch($tenantId, ' AND system_key = ?', [$systemRole->value])[0] ?? null;
    }

    /**
     * Rolle irgendeiner Stadt (nur Super-Admin: Rolle aus einer anderen Stadt übernehmen).
     */
    public function findInAnyTenant(int $id): ?Role
    {
        $stmt = $this->pdo->prepare('SELECT tenant_id FROM fe_roles WHERE id = ?');
        $stmt->execute([$id]);
        $tenantId = $stmt->fetchColumn();

        return $tenantId === false ? null : ($this->fetch((int) $tenantId, ' AND id = ?', [$id])[0] ?? null);
    }

    /**
     * Wer die Rollen der Stadt nutzt: Konten ohne gelöschte, offene Einladungscodes (Codes ohne Rolle
     * zählen zur Rolle Benutzer).
     *
     * @return array<int, RoleUsage> Rollen-ID → Nutzung (jede Rolle der Stadt)
     */
    public function usage(Tenant $tenant): array
    {
        $accounts = $this->pdo->prepare(
            'SELECT role_id, COUNT(*) FROM fe_users WHERE tenant_id = ? AND role_id IS NOT NULL'
            . " AND status <> 'deleted' GROUP BY role_id",
        );
        $accounts->execute([$tenant->id]);
        $accountsByRole = array_map('intval', $accounts->fetchAll(\PDO::FETCH_KEY_PAIR));

        $codes = $this->pdo->prepare(
            'SELECT COALESCE(c.role_id, r.id), COUNT(*) FROM fe_invitation_codes c'
            . ' LEFT JOIN fe_roles r ON c.role_id IS NULL AND r.tenant_id = c.tenant_id AND r.system_key = ?'
            . ' WHERE c.tenant_id = ? AND c.id IN (SELECT id FROM fe_invitation_codes WHERE tenant_id = ? AND '
            . InvitationCodeRepository::USABLE . ') GROUP BY COALESCE(c.role_id, r.id)',
        );
        $codes->execute([SystemRole::User->value, $tenant->id, $tenant->id]);
        $codesByRole = array_map('intval', $codes->fetchAll(\PDO::FETCH_KEY_PAIR));

        $ids = $this->pdo->prepare('SELECT id FROM fe_roles WHERE tenant_id = ? ORDER BY id');
        $ids->execute([$tenant->id]);
        $usage = [];
        foreach (array_map('intval', $ids->fetchAll(\PDO::FETCH_COLUMN)) as $id) {
            $usage[$id] = new RoleUsage($accountsByRole[$id] ?? 0, $codesByRole[$id] ?? 0);
        }

        return $usage;
    }

    public function usageOf(Tenant $tenant, int $id): RoleUsage
    {
        return $this->usage($tenant)[$id] ?? new RoleUsage(0, 0);
    }

    /**
     * Neue Rolle (ohne Kennzeichen system_key).
     *
     * @param int $updatedBy Konto, das anlegt (Super-Admin)
     *
     * @throws RoleRuleViolationException  F3
     * @throws InvalidRoleValueException   Standort oder Ausrüstungstyp nicht aus dieser Stadt
     * @throws DuplicateRoleNameException
     */
    public function create(Tenant $tenant, RoleDraft $draft, int $updatedBy): Role
    {
        $id = $this->transaction(function () use ($tenant, $draft, $updatedBy): int {
            $this->checkDraft($tenant, $draft);
            try {
                $this->pdo->prepare(
                    'INSERT INTO fe_roles (tenant_id, name, description, filter_locations, filter_device_types,'
                    . ' created_at, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)',
                )->execute([
                    $tenant->id,
                    $draft->name,
                    $draft->description,
                    (int) ($draft->locationIds !== null),
                    (int) ($draft->deviceTypeIds !== null),
                    $updatedBy,
                ]);
            } catch (\PDOException $e) {
                throw self::isError($e, self::ER_DUP_ENTRY) ? DuplicateRoleNameException::forName($draft->name) : $e;
            }
            $id = (int) $this->pdo->lastInsertId();
            $this->insertChildren($tenant, $id, $draft);

            return $id;
        });

        return $this->find($tenant, $id) ?? throw new \LogicException('Rolle fehlt nach dem Anlegen.');
    }

    /**
     * Speichert den ganzen Stand einer Rolle. Ohne Unterschied zum gespeicherten Stand wird nichts
     * geschrieben (version bleibt).
     *
     * @param int $version erwartete Version (gleichzeitiges Bearbeiten)
     *
     * @throws RoleNotFoundException
     * @throws RoleRuleViolationException   Rolle Admin, F3
     * @throws RoleVersionConflictException
     * @throws InvalidRoleValueException    Standort oder Ausrüstungstyp nicht aus dieser Stadt
     * @throws DuplicateRoleNameException
     */
    public function update(Tenant $tenant, int $id, int $version, RoleDraft $draft, int $updatedBy): Role
    {
        $this->transaction(function () use ($tenant, $id, $version, $draft, $updatedBy): int {
            $role = $this->lockRole($tenant, $id);
            if ($role->systemRole === SystemRole::Admin) {
                throw RoleRuleViolationException::adminNotEditable();
            }
            if ($role->version !== $version) {
                throw new RoleVersionConflictException($role, $this->usageOf($tenant, $id));
            }
            $this->checkDraft($tenant, $draft);
            if ($draft->sameAs($role)) {
                return $id;
            }

            try {
                $this->pdo->prepare(
                    'UPDATE fe_roles SET name = ?, description = ?, filter_locations = ?, filter_device_types = ?,'
                    . ' version = version + 1, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ? AND tenant_id = ?',
                )->execute([
                    $draft->name,
                    $draft->description,
                    (int) ($draft->locationIds !== null),
                    (int) ($draft->deviceTypeIds !== null),
                    $updatedBy,
                    $id,
                    $tenant->id,
                ]);
            } catch (\PDOException $e) {
                throw self::isError($e, self::ER_DUP_ENTRY) ? DuplicateRoleNameException::forName($draft->name) : $e;
            }
            foreach (['fe_role_areas', 'fe_role_actions', 'fe_role_locations', 'fe_role_device_types'] as $table) {
                $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE role_id = ? AND tenant_id = ?')->execute([$id, $tenant->id]);
            }
            $this->insertChildren($tenant, $id, $draft);

            return $id;
        });

        return $this->find($tenant, $id) ?? throw new \LogicException('Rolle fehlt nach dem Speichern.');
    }

    /**
     * Löscht eine Rolle ohne Konten und ohne offene Einladungscodes. Gelöschte (anonymisierte) Konten
     * der Rolle bekommen die Rolle Benutzer (sie bleiben für die Historie und brauchen eine Rolle,
     * CHECK chk_fe_users_role_id), nicht mehr nutzbare Codes keine Rolle (= Benutzer).
     *
     * @return Role der gelöschte Stand
     *
     * @throws RoleNotFoundException
     * @throws RoleRuleViolationException Standard-Rolle oder noch in Gebrauch (details: accounts, invitation_codes)
     */
    public function delete(Tenant $tenant, int $id): Role
    {
        $deleted = null;
        $this->transaction(function () use ($tenant, $id, &$deleted): int {
            $role = $this->lockRole($tenant, $id);
            if ($role->systemRole !== null) {
                throw RoleRuleViolationException::systemRoleNotDeletable($role);
            }
            // Erst nach FOR UPDATE gezählt (erste nicht sperrende Abfrage, also aktueller Stand).
            $usage = $this->usageOf($tenant, $id);
            if (!$usage->isUnused()) {
                throw RoleRuleViolationException::inUse($role, $usage);
            }
            $userRole = $this->findSystemRole($tenant, SystemRole::User) ?? throw new \LogicException('Rolle Benutzer fehlt.');
            $this->pdo->prepare("UPDATE fe_users SET role_id = ? WHERE tenant_id = ? AND role_id = ? AND status = 'deleted'")
                ->execute([$userRole->id, $tenant->id, $id]);
            $this->pdo->prepare('UPDATE fe_invitation_codes SET role_id = NULL WHERE tenant_id = ? AND role_id = ?')
                ->execute([$tenant->id, $id]);
            try {
                $this->pdo->prepare('DELETE FROM fe_roles WHERE id = ? AND tenant_id = ?')->execute([$id, $tenant->id]);
            } catch (\PDOException $e) {
                // Inzwischen von einem Konto belegt (Fremdschlüssel); zählt wie in Gebrauch.
                throw self::isError($e, self::ER_ROW_IS_REFERENCED) ? RoleRuleViolationException::inUse($role, new RoleUsage(1, 0)) : $e;
            }
            $deleted = $role;

            return $id;
        });

        return $deleted ?? throw new \LogicException('Rolle nicht gelöscht.');
    }

    /**
     * Freier Name: ohne $copy $base, sonst „$base (2)“, „$base (3)“ …; mit $copy „$base (Kopie)“,
     * sonst „$base (Kopie 2)“, „$base (Kopie 3)“ …. Gekürzt wird nur $base, sodass der Name höchstens
     * RoleDraft::MAX_NAME_LENGTH Zeichen hat (Vorgabe Holger 05.11.2026). Nur ein Vorschlag;
     * verbindlich bleibt der UNIQUE-Index beim Schreiben.
     */
    public function availableName(Tenant $tenant, string $base, bool $copy = false): string
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM fe_roles WHERE tenant_id = ? AND name = ?');
        for ($n = 1; ; ++$n) {
            $number = $n === 1 ? '' : ' ' . $n;
            $suffix = $copy ? ' (Kopie' . $number . ')' : ($n === 1 ? '' : ' (' . $n . ')');
            $candidate = rtrim(mb_substr($base, 0, RoleDraft::MAX_NAME_LENGTH - mb_strlen($suffix))) . $suffix;
            $stmt->execute([$tenant->id, $candidate]);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
        }
    }

    /**
     * F3 und Bezüge eines Entwurfs: Standorte und Ausrüstungstypen gehören zur Stadt (gelesen mit
     * LOCK IN SHARE MODE; gesperrte bzw. deaktivierte sind erlaubt).
     *
     * @throws RoleRuleViolationException
     * @throws InvalidRoleValueException
     */
    private function checkDraft(Tenant $tenant, RoleDraft $draft): void
    {
        $conflicts = $draft->filterConflicts();
        if ($conflicts['labels'] !== []) {
            throw RoleRuleViolationException::administrationWithFilter($conflicts['areas'], $conflicts['actions'], $conflicts['labels']);
        }
        foreach ([
            ['fe_locations', $draft->locationIds, 'Den Standort #%d gibt es in dieser Stadt nicht.'],
            ['fe_device_types', $draft->deviceTypeIds, 'Den Ausrüstungstyp #%d gibt es in dieser Stadt nicht.'],
        ] as [$table, $ids, $message]) {
            if ($ids === null) {
                continue;
            }
            $stmt = $this->pdo->prepare(
                'SELECT id FROM ' . $table . ' WHERE tenant_id = ? AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
                . ' LOCK IN SHARE MODE',
            );
            $stmt->execute([$tenant->id, ...$ids]);
            $missing = array_values(array_diff($ids, array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));
            if ($missing !== []) {
                throw new InvalidRoleValueException(sprintf($message, $missing[0]));
            }
        }
    }

    private function insertChildren(Tenant $tenant, int $roleId, RoleDraft $draft): void
    {
        $insertArea = $this->pdo->prepare('INSERT INTO fe_role_areas (role_id, tenant_id, area, level) VALUES (?, ?, ?, ?)');
        foreach ($draft->areas as $area => $level) {
            $insertArea->execute([$roleId, $tenant->id, $area, $level->value]);
        }
        $insertAction = $this->pdo->prepare('INSERT INTO fe_role_actions (role_id, tenant_id, action) VALUES (?, ?, ?)');
        foreach ($draft->actions as $action) {
            $insertAction->execute([$roleId, $tenant->id, $action->value]);
        }
        $insertLocation = $this->pdo->prepare('INSERT INTO fe_role_locations (role_id, tenant_id, location_id) VALUES (?, ?, ?)');
        foreach ($draft->locationIds ?? [] as $locationId) {
            $insertLocation->execute([$roleId, $tenant->id, $locationId]);
        }
        $insertType = $this->pdo->prepare('INSERT INTO fe_role_device_types (role_id, tenant_id, device_type_id) VALUES (?, ?, ?)');
        foreach ($draft->deviceTypeIds ?? [] as $typeId) {
            $insertType->execute([$roleId, $tenant->id, $typeId]);
        }
    }

    /**
     * @throws RoleNotFoundException
     */
    private function lockRole(Tenant $tenant, int $id): Role
    {
        $stmt = $this->pdo->prepare('SELECT id FROM fe_roles WHERE id = ? AND tenant_id = ? FOR UPDATE');
        $stmt->execute([$id, $tenant->id]);
        if ($stmt->fetchColumn() === false) {
            throw RoleNotFoundException::forId($id);
        }

        return $this->find($tenant, $id) ?? throw RoleNotFoundException::forId($id);
    }

    /**
     * @param \Closure(): int $work
     */
    private function transaction(\Closure $work): int
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Rollen werden in einer eigenen Transaktion geändert.');
        }
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function isError(\PDOException $e, int $code): bool
    {
        return ($e->errorInfo[1] ?? null) === $code;
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
    private function fetch(int $tenantId, string $where, array $params): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fe_roles WHERE tenant_id = ?' . $where
            . " ORDER BY CASE system_key WHEN 'admin' THEN 0 WHEN 'user' THEN 1 ELSE 2 END, name, id",
        );
        $stmt->execute([$tenantId, ...$params]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $areas = $this->children($tenantId, $ids, 'SELECT role_id, area, level FROM fe_role_areas', 'area');
        $actions = $this->children($tenantId, $ids, 'SELECT role_id, action FROM fe_role_actions', 'action');
        $locations = $this->children($tenantId, $ids, 'SELECT role_id, location_id FROM fe_role_locations', 'location_id');
        $deviceTypes = $this->children($tenantId, $ids, 'SELECT role_id, device_type_id FROM fe_role_device_types', 'device_type_id');

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
    private function children(int $tenantId, array $roleIds, string $select, string $orderColumn): array
    {
        $stmt = $this->pdo->prepare(
            $select . ' WHERE tenant_id = ? AND role_id IN (' . implode(', ', array_fill(0, count($roleIds), '?')) . ')'
            . ' ORDER BY role_id, ' . $orderColumn,
        );
        $stmt->execute([$tenantId, ...$roleIds]);

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
