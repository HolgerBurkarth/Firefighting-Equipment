<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Role;

/**
 * Stand vor Migration 0036 für Tests auf Basis von AuthSchemaTestCase: Konten ohne Rolle (nur mit
 * abgeschalteten CHECKs anlegbar, sobald 0037 gilt).
 */
trait LegacyRoleAccounts
{
    /**
     * Stand vor Migration 0036: Konten in jedem Status ohne Rolle, dazu ein Konto mit Rolle (nach
     * Schritt 1.2 angelegt) und ein Super-Admin.
     */
    protected function legacyAccounts(): void
    {
        $immendingen = $this->createTenant('immendingen');
        $tuttlingen = $this->createTenant('tuttlingen');
        $this->createSuperadmin();
        $this->createUser($tuttlingen);

        $this->withoutChecks(function () use ($immendingen, $tuttlingen): void {
            $admin = $this->createUser($immendingen, ['role' => 'admin', 'role_id' => null]);
            $this->createUser($immendingen, ['role' => 'admin', 'role_id' => null, 'status' => 'invited',
                'password_hash' => null, 'email_verified_at' => null, 'name' => null, 'display_name' => null]);
            $this->createUser($immendingen, ['role_id' => null]);
            $this->createUser($immendingen, ['role_id' => null, 'locked_at' => self::CREATED_AT]);
            $code = $this->createInvitationCode($immendingen, $admin);
            $this->createUser($immendingen, ['role_id' => null, 'status' => 'pending', 'invitation_code_id' => $code, 'display_name' => null]);
            $this->createUser($immendingen, ['role_id' => null, 'status' => 'unverified', 'invitation_code_id' => $code,
                'email_verified_at' => null, 'display_name' => null]);
            $this->createUser($immendingen, ['role_id' => null, 'status' => 'deleted', 'email' => null, 'email_verified_at' => null,
                'password_hash' => null, 'name' => null, 'display_name' => null, 'deleted_at' => self::CREATED_AT]);
            $this->createUser($tuttlingen, ['role_id' => null]);
        });
    }

    protected function createSuperadminWithRole(): void
    {
        $this->createUser(null, ['role' => 'superadmin', 'role_id' => $this->systemRoleId($this->tenantId('immendingen'), 'admin')]);
    }

    protected function tenantId(string $slug): int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM fe_tenants WHERE slug = ?');
        $stmt->execute([$slug]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Ab Migration 0037 verlangt die Datenbank eine Rolle je Konto einer Stadt; den Stand davor gibt
     * es nur mit abgeschalteten CHECKs dieser Sitzung.
     */
    protected function withoutChecks(\Closure $work): void
    {
        $this->pdo()->exec('SET SESSION check_constraint_checks = 0');
        try {
            $work();
        } finally {
            $this->pdo()->exec('SET SESSION check_constraint_checks = 1');
        }
    }
}
