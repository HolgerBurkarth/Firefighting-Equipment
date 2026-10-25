-- Gerätetypen je Stadt, vom Admin pflegbar. Nie gelöscht, nur deaktiviert (active = 0); bestehende
-- Geräte behalten ihren Typ. Der Name ist je Stadt eindeutig (utf8mb4_unicode_ci: ohne Groß- und
-- Kleinschreibung). (id, tenant_id) ist Ziel der zusammengesetzten Fremdschlüssel aus fe_devices.
CREATE TABLE fe_device_types (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id  INT UNSIGNED NOT NULL,
    name       VARCHAR(50)  NOT NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_device_types_id_tenant (id, tenant_id),
    UNIQUE KEY uq_fe_device_types_tenant_name (tenant_id, name),
    CONSTRAINT fk_fe_device_types_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT chk_fe_device_types_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
    CONSTRAINT chk_fe_device_types_active CHECK (active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
