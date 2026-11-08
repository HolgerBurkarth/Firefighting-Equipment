-- Aufgaben von Standorten (Phase 2, Schritt P2.2, docs/PLAN-PHASE-2.md): Der Super-Admin schaltet
-- je Stadt frei, welche Aufgaben es gibt (Lager, Sammelstelle, Werkstatt, Rückgabe, Ausgeliehen an),
-- und kann den Anzeigenamen ändern (leer = Standardname im Code). Die Werte stehen fest im Code
-- (LocationTask); eine Zeile existiert nur für freigeschaltete oder umbenannte Aufgaben.
CREATE TABLE fe_tenant_location_tasks (
    tenant_id  INT UNSIGNED NOT NULL,
    task       VARCHAR(20)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label      VARCHAR(50)  NULL,
    enabled    TINYINT(1)   NOT NULL DEFAULT 1,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, task),
    CONSTRAINT fk_fe_tenant_location_tasks_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT chk_fe_tenant_location_tasks_task CHECK (task IN ('store', 'collection', 'workshop', 'returns', 'borrower')),
    CONSTRAINT chk_fe_tenant_location_tasks_label CHECK (label IS NULL OR CHAR_LENGTH(TRIM(label)) > 0),
    CONSTRAINT chk_fe_tenant_location_tasks_enabled CHECK (enabled IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
