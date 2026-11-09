-- Ausrüstungstypen einer Regel des Sollbestands (Phase 2, P2.7): mindestens ein Typ je Regel (prüft der
-- Code). Fällt eine Regel weg, fallen ihre Typen mit (ON DELETE CASCADE nur hier, wie bei den Rollen).
CREATE TABLE fe_stock_rule_types (
    rule_id        INT UNSIGNED NOT NULL,
    tenant_id      INT UNSIGNED NOT NULL,
    device_type_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (rule_id, device_type_id),
    KEY idx_fe_stock_rule_types_type (device_type_id, tenant_id),
    CONSTRAINT fk_fe_stock_rule_types_rule FOREIGN KEY (rule_id, tenant_id)
        REFERENCES fe_stock_rules (id, tenant_id) ON DELETE CASCADE,
    CONSTRAINT fk_fe_stock_rule_types_type FOREIGN KEY (device_type_id, tenant_id)
        REFERENCES fe_device_types (id, tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
