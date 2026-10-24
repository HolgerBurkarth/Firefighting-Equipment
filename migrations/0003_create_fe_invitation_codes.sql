-- Einladungscodes einer Stadt, nur als SHA-256 der normalisierten Form gespeichert (der Klartext
-- wird einmal angezeigt). Höchstens 90 Tage gültig. Der Ersteller muss zur selben Stadt gehören
-- (zusammengesetzter Fremdschlüssel; der Super-Admin hat keine Stadt und kann keine anlegen).
CREATE TABLE fe_invitation_codes (
    id         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    tenant_id  INT UNSIGNED      NOT NULL,
    code_hash  VARBINARY(32)     NOT NULL,
    label      VARCHAR(100)      NULL,
    max_uses   SMALLINT UNSIGNED NOT NULL,
    use_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME          NOT NULL,
    revoked_at DATETIME          NULL,
    created_by INT UNSIGNED      NOT NULL,
    created_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_invitation_codes_code_hash (code_hash),
    UNIQUE KEY uq_fe_invitation_codes_id_tenant (id, tenant_id),
    KEY idx_fe_invitation_codes_tenant (tenant_id),
    KEY idx_fe_invitation_codes_created_by (created_by, tenant_id),
    CONSTRAINT fk_fe_invitation_codes_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_invitation_codes_created_by
        FOREIGN KEY (created_by, tenant_id) REFERENCES fe_users (id, tenant_id),
    CONSTRAINT chk_fe_invitation_codes_code_hash CHECK (OCTET_LENGTH(code_hash) = 32),
    CONSTRAINT chk_fe_invitation_codes_label CHECK (label IS NULL OR CHAR_LENGTH(TRIM(label)) > 0),
    CONSTRAINT chk_fe_invitation_codes_max_uses CHECK (max_uses >= 1),
    CONSTRAINT chk_fe_invitation_codes_use_count CHECK (use_count <= max_uses),
    CONSTRAINT chk_fe_invitation_codes_expires
        CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 90 DAY),
    CONSTRAINT chk_fe_invitation_codes_revoked CHECK (revoked_at IS NULL OR revoked_at >= created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
