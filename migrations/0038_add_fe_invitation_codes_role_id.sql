-- Rolle, die ein Konto nach der Registrierung mit diesem Code erhält (Phase 1, Schritt 1.6). NULL =
-- Standard-Rolle Benutzer, daher bleiben alle bisherigen Codes ohne Änderung gültig. Die Rolle gehört
-- zur Stadt des Codes (zusammengesetzter Fremdschlüssel). Dass die Rolle Admin nicht wählbar ist,
-- prüft der Code (system_key steht in fe_roles).
ALTER TABLE fe_invitation_codes
    ADD COLUMN role_id INT UNSIGNED NULL AFTER label,
    ADD KEY idx_fe_invitation_codes_role (role_id, tenant_id),
    ADD CONSTRAINT fk_fe_invitation_codes_role FOREIGN KEY (role_id, tenant_id) REFERENCES fe_roles (id, tenant_id);
