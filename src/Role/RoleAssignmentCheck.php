<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Probelauf zu Migration 0036 (Konten auf Standard-Rollen) und Vorabprüfung für 0037 (CHECK
 * chk_fe_users_role_id), nur lesend (bin/role-assignment-check.php). JOIN und WHERE der
 * zuzuordnenden Konten sind dieselben wie im UPDATE von 0036 (per Test: gezählt wird genau, was die
 * Migration ändert).
 */
final readonly class RoleAssignmentCheck
{
    private const string GROUPED = 'SELECT t.slug, u.role, u.status, COUNT(*) AS accounts FROM fe_users u'
        . ' JOIN fe_tenants t ON t.id = u.tenant_id';
    private const string GROUP_BY = ' GROUP BY t.slug, u.role, u.status ORDER BY t.slug, u.role, u.status';

    public function __construct(private \PDO $pdo)
    {
    }

    public function run(): RoleAssignmentReport
    {
        return new RoleAssignmentReport(
            // Wie 0036: Konten einer Stadt ohne Rolle, deren Kontoart eine Standard-Rolle hat.
            $this->groups(
                self::GROUPED
                . ' JOIN fe_roles r ON r.tenant_id = u.tenant_id AND r.system_key = u.role'
                . " WHERE u.tenant_id IS NOT NULL AND u.role_id IS NULL AND u.role IN ('admin', 'user')",
            ),
            // Blieben nach 0036 ohne Rolle (0037 schlüge fehl).
            $this->groups(
                self::GROUPED
                . ' LEFT JOIN fe_roles r ON r.tenant_id = u.tenant_id AND r.system_key = u.role'
                . ' WHERE u.role_id IS NULL AND r.id IS NULL',
            ),
            $this->count('SELECT COUNT(*) FROM fe_users WHERE tenant_id IS NOT NULL AND role_id IS NOT NULL'),
            $this->count(
                'SELECT COUNT(*) FROM fe_users u JOIN fe_roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id'
                . " WHERE (u.role = 'admin') <> (r.system_key <=> 'admin')",
            ),
            $this->count('SELECT COUNT(*) FROM fe_users WHERE tenant_id IS NULL AND role_id IS NOT NULL'),
            $this->count('SELECT COUNT(*) FROM fe_users WHERE tenant_id IS NULL'),
        );
    }

    /**
     * @param string $sql SQL aus diesem Code, nie aus Eingaben
     *
     * @return list<RoleAssignmentGroup> nach Kürzel, Kontoart und Status
     */
    private function groups(string $sql): array
    {
        return array_map(
            static fn (array $row): RoleAssignmentGroup => new RoleAssignmentGroup(
                (string) $row['slug'],
                (string) $row['role'],
                (string) $row['status'],
                (int) $row['accounts'],
            ),
            $this->pdo->query($sql . self::GROUP_BY)->fetchAll(),
        );
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
