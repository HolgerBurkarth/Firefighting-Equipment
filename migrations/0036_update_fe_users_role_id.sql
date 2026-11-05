-- Ordnet jedes Konto einer Stadt seiner Standard-Rolle zu (Phase 1, Schritt 1.2): Kontoart admin →
-- Rolle Admin, user → Rolle Benutzer (fe_roles.system_key = fe_users.role, Migration 0034), in jedem
-- Status (auch invited, unverified, pending, deleted). Super-Admins (tenant_id NULL) bleiben ohne
-- Rolle. Ändert bestehende Daten (nur die in 0035 neue Spalte role_id, updated_at bleibt); Freigabe
-- und Probelauf siehe docs/PLAN-PHASE-1-ROLLEN.md. Eine einzige Anweisung: InnoDB übernimmt sie ganz oder gar
-- nicht. Wiederholbar (nur Konten ohne Rolle), falls sie lief, aber nicht protokolliert wurde.
-- Probelauf vorher: php bin/role-assignment-check.php (zählt dieselben Zeilen, ändert nichts).
UPDATE fe_users u
JOIN fe_roles r ON r.tenant_id = u.tenant_id AND r.system_key = u.role
SET u.role_id = r.id
WHERE u.tenant_id IS NOT NULL AND u.role_id IS NULL AND u.role IN ('admin', 'user');
