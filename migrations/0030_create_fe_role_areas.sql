-- Bereiche einer Rolle mit Stufe: read (Ansehen) oder write (Ändern, schließt Ansehen ein). Kein
-- Zugriff = keine Zeile. Gültige Schlüssel stehen im Rechte-Katalog des Codes; die Datenbank prüft
-- nur das Format. Gelöscht wird nur mit der Rolle (CASCADE) oder beim Speichern der Rolle.
CREATE TABLE fe_role_areas (
    role_id   INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    area      VARCHAR(30)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    level     VARCHAR(5)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (role_id, area),
    KEY idx_fe_role_areas_role (role_id, tenant_id),
    CONSTRAINT fk_fe_role_areas_role FOREIGN KEY (role_id, tenant_id)
        REFERENCES fe_roles (id, tenant_id) ON DELETE CASCADE,
    CONSTRAINT chk_fe_role_areas_area CHECK (area REGEXP '^[a-z_]{1,30}\\z'),
    CONSTRAINT chk_fe_role_areas_level CHECK (level IN ('read', 'write'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
