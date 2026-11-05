-- Rolle eines Kontos (Phase 1). Super-Admins haben keine Rolle (tenant_id NULL, der Fremdschlüssel
-- prüft dann nicht). Die Rolle gehört zur Stadt des Kontos (zusammengesetzter Fremdschlüssel).
-- Vorerst NULL erlaubt; Migration 0036 ordnet die bestehenden Konten zu, 0037 verlangt danach eine
-- Rolle für jedes Konto einer Stadt. fe_users.role bleibt als Kontoart (admin = hat die Rolle Admin).
ALTER TABLE fe_users
    ADD COLUMN role_id INT UNSIGNED NULL AFTER role,
    ADD KEY idx_fe_users_role (role_id, tenant_id),
    ADD CONSTRAINT fk_fe_users_role FOREIGN KEY (role_id, tenant_id) REFERENCES fe_roles (id, tenant_id);
