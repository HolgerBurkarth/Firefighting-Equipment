-- Sollbestand (Phase 2, Schritt P2.7, docs/PLAN-PHASE-2.md): je Standort (Ebene 1 oder Fach) beliebig
-- viele Regeln "mindestens N einsatzbereite Ausrüstungen dieser Typen". Die Typen einer Regel sind
-- gleichwertig ("oder", sie zählen zusammen; Tabelle fe_stock_rule_types). Bei einer Regel für die Ebene 1
-- zählen auch die Fächer mit. "Außer Betrieb" ist keine Spalte, sondern wird berechnet: ein Standort ist
-- außer Betrieb, sobald eine Regel unterschritten ist. Regeln sind Einstellungen, keine Historie: sie
-- werden beim Speichern ersetzt.
CREATE TABLE fe_stock_rules (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    tenant_id   INT UNSIGNED     NOT NULL,
    location_id INT UNSIGNED     NOT NULL,
    min_count   TINYINT UNSIGNED NOT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_stock_rules_id_tenant (id, tenant_id),
    KEY idx_fe_stock_rules_location (location_id, tenant_id),
    CONSTRAINT fk_fe_stock_rules_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_stock_rules_location FOREIGN KEY (location_id, tenant_id)
        REFERENCES fe_locations (id, tenant_id),
    CONSTRAINT chk_fe_stock_rules_min CHECK (min_count BETWEEN 1 AND 99)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
