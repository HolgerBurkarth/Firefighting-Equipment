-- Codes der Anmeldung über „Claude verbinden“ (OAuth Authorization Code mit PKCE S256,
-- docs/PLAN-MCP.md, Abschnitt 5): nur SHA-256 des Codes, einmal einlösbar (Einlösen = Zeile
-- löschen), höchstens 10 Minuten gültig. access = gewährter Zugang (read/write). Alle Wege, die die
-- Sitzungen eines Kontos beenden, löschen auch seine Codes. Fremdschlüssel auf fe_users
-- kaskadieren nicht; eine gelöschte Registrierung nimmt ihre Codes mit (CASCADE).
CREATE TABLE fe_oauth_codes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED    NOT NULL,
    user_id         INT UNSIGNED    NOT NULL,
    oauth_client_id INT UNSIGNED    NOT NULL,
    code_hash       BINARY(32)      NOT NULL,
    redirect_uri    VARCHAR(200)    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code_challenge  CHAR(43)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    access          VARCHAR(5)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_oauth_codes_code_hash (code_hash),
    KEY idx_fe_oauth_codes_user (user_id, tenant_id),
    KEY idx_fe_oauth_codes_client (oauth_client_id, tenant_id),
    KEY idx_fe_oauth_codes_tenant_expires (tenant_id, expires_at),
    CONSTRAINT fk_fe_oauth_codes_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_oauth_codes_user FOREIGN KEY (user_id, tenant_id)
        REFERENCES fe_users (id, tenant_id),
    CONSTRAINT fk_fe_oauth_codes_client FOREIGN KEY (oauth_client_id, tenant_id)
        REFERENCES fe_oauth_clients (id, tenant_id) ON DELETE CASCADE,
    CONSTRAINT chk_fe_oauth_codes_code_challenge
        CHECK (code_challenge REGEXP '^[A-Za-z0-9_-]{43}\\z'),
    CONSTRAINT chk_fe_oauth_codes_redirect_uri CHECK (CHAR_LENGTH(redirect_uri) > 0),
    CONSTRAINT chk_fe_oauth_codes_access CHECK (access IN ('read', 'write')),
    CONSTRAINT chk_fe_oauth_codes_expires
        CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 10 MINUTE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
