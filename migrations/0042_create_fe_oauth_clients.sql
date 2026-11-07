-- Registrierungen von Claude als OAuth-Client (RFC 7591, docs/PLAN-MCP.md, Abschnitt 5), je Stadt.
-- client_id: zufällig (32 Byte base64url, 43 Zeichen), öffentlich, eindeutig über alle Städte.
-- redirect_uris: JSON-Liste mit 1 bis 5 erlaubten Rücksprung-Adressen (geprüft vom Code).
-- last_used_at: letzte Anmeldung über diesen Client (NULL = nie genutzt; nie genutzte räumt die
-- Registrierung ohne Cronjob auf). Löschen ist ein echtes DELETE (keine Historie).
CREATE TABLE fe_oauth_clients (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     INT UNSIGNED NOT NULL,
    client_id     VARCHAR(43)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_name   VARCHAR(100) NULL,
    redirect_uris JSON         NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_oauth_clients_client_id (client_id),
    UNIQUE KEY uq_fe_oauth_clients_id_tenant (id, tenant_id),
    KEY idx_fe_oauth_clients_tenant_created (tenant_id, created_at),
    CONSTRAINT fk_fe_oauth_clients_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT chk_fe_oauth_clients_client_id CHECK (client_id REGEXP '^[A-Za-z0-9_-]{43}\\z'),
    CONSTRAINT chk_fe_oauth_clients_client_name
        CHECK (client_name IS NULL OR CHAR_LENGTH(TRIM(client_name)) > 0),
    CONSTRAINT chk_fe_oauth_clients_redirect_uris CHECK (JSON_VALID(redirect_uris)
        AND JSON_TYPE(redirect_uris) = 'ARRAY' AND JSON_LENGTH(redirect_uris) BETWEEN 1 AND 5),
    CONSTRAINT chk_fe_oauth_clients_last_used CHECK (last_used_at IS NULL OR last_used_at >= created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
