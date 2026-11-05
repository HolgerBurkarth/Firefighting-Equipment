-- Aktionen einer Rolle (z. B. book.move = Umbuchen). Gültige Schlüssel stehen im Rechte-Katalog des
-- Codes; die Datenbank prüft nur das Format. Gelöscht wird nur mit der Rolle (CASCADE) oder beim
-- Speichern der Rolle.
CREATE TABLE fe_role_actions (
    role_id   INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    action    VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (role_id, action),
    KEY idx_fe_role_actions_role (role_id, tenant_id),
    CONSTRAINT fk_fe_role_actions_role FOREIGN KEY (role_id, tenant_id)
        REFERENCES fe_roles (id, tenant_id) ON DELETE CASCADE,
    CONSTRAINT chk_fe_role_actions_action CHECK (action REGEXP '^[a-z_.]{1,40}\\z')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
