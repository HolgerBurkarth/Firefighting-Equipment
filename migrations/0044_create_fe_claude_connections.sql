-- Claude-Verbindungen (docs/PLAN-MCP.md, Abschnitte 2 und 3): je Verbindung ein rotierendes
-- Refresh-Token und ein kurzlebiges Zugriffstoken, nur als SHA-256. access = bei der Zustimmung
-- gewährter Zugang (wirksam ist das Minimum mit fe_users.claude_access). account_kind = Kontoart beim
-- Verbinden (ein Wechsel zu oder von Admin beendet die Verbindung). previous_refresh_hash/rotated_at:
-- das vorige Refresh-Token nach der letzten Rotation (beide gesetzt oder beide NULL). expires_at =
-- Höchstdauer (höchstens 90 Tage wie fe_sessions; die Inaktivität prüft der Code nach der
-- Sitzungsklasse der aktuellen Rolle). Alle Wege, die die Sitzungen eines Kontos beenden, löschen
-- auch seine Verbindungen (echtes DELETE). Fremdschlüssel kaskadieren nicht.
CREATE TABLE fe_claude_connections (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id             INT UNSIGNED    NOT NULL,
    user_id               INT UNSIGNED    NOT NULL,
    oauth_client_id       INT UNSIGNED    NOT NULL,
    access                VARCHAR(5)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    account_kind          VARCHAR(5)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    refresh_hash          BINARY(32)      NOT NULL,
    previous_refresh_hash BINARY(32)      NULL,
    rotated_at            DATETIME        NULL,
    access_hash           BINARY(32)      NULL,
    access_expires_at     DATETIME        NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at            DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_claude_connections_refresh (refresh_hash),
    UNIQUE KEY uq_fe_claude_connections_previous (previous_refresh_hash),
    UNIQUE KEY uq_fe_claude_connections_access (access_hash),
    KEY idx_fe_claude_connections_user (user_id, tenant_id),
    KEY idx_fe_claude_connections_client (oauth_client_id, tenant_id),
    KEY idx_fe_claude_connections_tenant_expires (tenant_id, expires_at),
    CONSTRAINT fk_fe_claude_connections_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_claude_connections_user FOREIGN KEY (user_id, tenant_id)
        REFERENCES fe_users (id, tenant_id),
    CONSTRAINT fk_fe_claude_connections_client FOREIGN KEY (oauth_client_id, tenant_id)
        REFERENCES fe_oauth_clients (id, tenant_id),
    CONSTRAINT chk_fe_claude_connections_access CHECK (access IN ('read', 'write')),
    CONSTRAINT chk_fe_claude_connections_account_kind CHECK (account_kind IN ('admin', 'user')),
    CONSTRAINT chk_fe_claude_connections_previous
        CHECK ((previous_refresh_hash IS NULL) = (rotated_at IS NULL)),
    CONSTRAINT chk_fe_claude_connections_access_token
        CHECK ((access_hash IS NULL) = (access_expires_at IS NULL)
            AND (access_expires_at IS NULL OR access_expires_at <= expires_at)),
    CONSTRAINT chk_fe_claude_connections_last_seen CHECK (last_seen_at >= created_at),
    CONSTRAINT chk_fe_claude_connections_expires
        CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 90 DAY)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
