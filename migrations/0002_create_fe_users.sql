-- Benutzerkonten. Super-Admin genau dann ohne Stadt. tenant_key macht die E-Mail auch für
-- Super-Admins (tenant_id NULL) eindeutig; anonymisierte Konten haben email NULL und belegen keine
-- Adresse. Die Regeln des Lebenszyklus (status) prüft die Datenbank per CHECK. Feste Werte (role,
-- status) sind ascii_bin, damit IN (...) Groß- und Kleinschreibung unterscheidet. email ist
-- utf8mb4_bin, damit z. B. "ß"/"ss" oder "é"/"e" nicht als gleich gelten (Kleinschreibung per CHECK).
CREATE TABLE fe_users (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          INT UNSIGNED NULL,
    tenant_key         INT UNSIGNED AS (IFNULL(tenant_id, 0)) STORED,
    role               VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'user',
    status             VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email              VARCHAR(254) COLLATE utf8mb4_bin NULL,
    email_verified_at  DATETIME     NULL,
    password_hash      VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    name               VARCHAR(100) NULL,
    display_name       VARCHAR(100) NULL,
    invitation_code_id INT UNSIGNED NULL,
    locked_at          DATETIME     NULL,
    deleted_at         DATETIME     NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_users_id_tenant (id, tenant_id),
    UNIQUE KEY uq_fe_users_tenant_email (tenant_key, email),
    KEY idx_fe_users_tenant (tenant_id),
    KEY idx_fe_users_invitation_code (invitation_code_id, tenant_id),
    CONSTRAINT fk_fe_users_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT chk_fe_users_role CHECK (role IN ('superadmin', 'admin', 'user')),
    CONSTRAINT chk_fe_users_role_tenant CHECK ((role = 'superadmin') = (tenant_id IS NULL)),
    CONSTRAINT chk_fe_users_status
        CHECK (status IN ('invited', 'unverified', 'pending', 'active', 'deleted')),
    CONSTRAINT chk_fe_users_superadmin_status
        CHECK (role <> 'superadmin' OR status IN ('active', 'deleted')),
    CONSTRAINT chk_fe_users_email CHECK (email IS NULL OR (
        email COLLATE utf8mb4_bin REGEXP '^[^@[:space:][:cntrl:]]+@[^@[:space:][:cntrl:]]+\\z'
        AND CAST(email AS BINARY) = CAST(LOWER(email) AS BINARY))),
    CONSTRAINT chk_fe_users_password_hash
        CHECK (password_hash IS NULL OR password_hash LIKE '$argon2id$%'),
    CONSTRAINT chk_fe_users_name CHECK (name IS NULL OR CHAR_LENGTH(TRIM(name)) > 0),
    CONSTRAINT chk_fe_users_display_name
        CHECK (display_name IS NULL OR CHAR_LENGTH(TRIM(display_name)) > 0),
    CONSTRAINT chk_fe_users_deleted_at CHECK ((status = 'deleted') = (deleted_at IS NOT NULL)),
    CONSTRAINT chk_fe_users_anonymized CHECK (status <> 'deleted' OR (email IS NULL
        AND email_verified_at IS NULL AND password_hash IS NULL AND name IS NULL
        AND display_name IS NULL)),
    CONSTRAINT chk_fe_users_email_required CHECK (status = 'deleted' OR email IS NOT NULL),
    CONSTRAINT chk_fe_users_invited
        CHECK (status <> 'invited' OR (password_hash IS NULL AND email_verified_at IS NULL)),
    CONSTRAINT chk_fe_users_registered CHECK (status NOT IN ('unverified', 'pending')
        OR (password_hash IS NOT NULL AND name IS NOT NULL AND invitation_code_id IS NOT NULL)),
    CONSTRAINT chk_fe_users_unverified
        CHECK (status <> 'unverified' OR email_verified_at IS NULL),
    CONSTRAINT chk_fe_users_verified
        CHECK (status NOT IN ('pending', 'active') OR email_verified_at IS NOT NULL),
    CONSTRAINT chk_fe_users_active
        CHECK (status <> 'active' OR (password_hash IS NOT NULL AND display_name IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
