-- Datenfilter "Sieht nur …" einer Rolle nach Ausrüstungstypen (wirksam nur mit
-- fe_roles.filter_device_types = 1). Typ und Rolle gehören zur selben Stadt (zusammengesetzte
-- Fremdschlüssel). Gerätetypen werden nie gelöscht, daher kein CASCADE auf fe_device_types.
CREATE TABLE fe_role_device_types (
    role_id        INT UNSIGNED NOT NULL,
    tenant_id      INT UNSIGNED NOT NULL,
    device_type_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, device_type_id),
    KEY idx_fe_role_device_types_role (role_id, tenant_id),
    KEY idx_fe_role_device_types_type (device_type_id, tenant_id),
    CONSTRAINT fk_fe_role_device_types_role FOREIGN KEY (role_id, tenant_id)
        REFERENCES fe_roles (id, tenant_id) ON DELETE CASCADE,
    CONSTRAINT fk_fe_role_device_types_type FOREIGN KEY (device_type_id, tenant_id)
        REFERENCES fe_device_types (id, tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
