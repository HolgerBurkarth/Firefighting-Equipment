-- Rückgabe ohne Anmeldung (Phase 2, Schritt P2.6, docs/PLAN-PHASE-2.md): je Standort mit der Aufgabe
-- "Rückgabe" höchstens ein gültiger geheimer Schlüssel (Aushang mit QR-Code). Gespeichert wird nur der
-- SHA-256 des Schlüssels (32 Byte). Erneuern widerruft den alten (revoked_at) und legt einen neuen an;
-- Zeilen werden nie gelöscht (Ausnahme "Gerätedaten zurücksetzen").
CREATE TABLE fe_return_points (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   INT UNSIGNED    NOT NULL,
    location_id INT UNSIGNED    NOT NULL,
    secret_hash BINARY(32)      NOT NULL,
    created_by  INT UNSIGNED    NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at  DATETIME        NULL,
    active_key  INT UNSIGNED AS (IF(revoked_at IS NULL, location_id, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_return_points_secret (secret_hash),
    UNIQUE KEY uq_fe_return_points_active (active_key),
    KEY idx_fe_return_points_location (location_id, tenant_id),
    KEY idx_fe_return_points_user (created_by, tenant_id),
    CONSTRAINT fk_fe_return_points_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_return_points_location FOREIGN KEY (location_id, tenant_id)
        REFERENCES fe_locations (id, tenant_id),
    CONSTRAINT fk_fe_return_points_user FOREIGN KEY (created_by, tenant_id)
        REFERENCES fe_users (id, tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
