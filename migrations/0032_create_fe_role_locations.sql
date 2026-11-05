-- Datenfilter "Sieht nur …" einer Rolle nach Standorten (wirksam nur mit fe_roles.filter_locations
-- = 1). Ein Standort der Ebene 1 schließt seine Fächer ein, einzelne Fächer sind ebenfalls wählbar.
-- Standort und Rolle gehören zur selben Stadt (zusammengesetzte Fremdschlüssel). Standorte werden nie
-- gelöscht (Ausnahme "Gerätedaten zurücksetzen"), daher kein CASCADE auf fe_locations.
CREATE TABLE fe_role_locations (
    role_id     INT UNSIGNED NOT NULL,
    tenant_id   INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, location_id),
    KEY idx_fe_role_locations_role (role_id, tenant_id),
    KEY idx_fe_role_locations_location (location_id, tenant_id),
    CONSTRAINT fk_fe_role_locations_role FOREIGN KEY (role_id, tenant_id)
        REFERENCES fe_roles (id, tenant_id) ON DELETE CASCADE,
    CONSTRAINT fk_fe_role_locations_location FOREIGN KEY (location_id, tenant_id)
        REFERENCES fe_locations (id, tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
