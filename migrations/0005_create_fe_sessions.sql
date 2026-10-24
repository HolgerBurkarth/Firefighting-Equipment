-- Sitzungen; im Cookie steht nur das zufällige Token, hier nur dessen SHA-256. Die
-- Inaktivitätsgrenze ergibt sich zur Laufzeit aus der Rolle und last_seen_at; expires_at ist die
-- Höchstdauer (höchstens 90 Tage, die längste aller Rollen). device_label ist nur eine grobe
-- Gerätebezeichnung (z. B. "Chrome auf Android"), nie der vollständige User-Agent.
CREATE TABLE fe_sessions (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED    NOT NULL,
    token_hash   VARBINARY(32)   NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at   DATETIME        NOT NULL,
    device_label VARCHAR(100)    NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_sessions_token_hash (token_hash),
    KEY idx_fe_sessions_user (user_id),
    KEY idx_fe_sessions_expires (expires_at),
    CONSTRAINT fk_fe_sessions_user FOREIGN KEY (user_id) REFERENCES fe_users (id),
    CONSTRAINT chk_fe_sessions_token_hash CHECK (OCTET_LENGTH(token_hash) = 32),
    CONSTRAINT chk_fe_sessions_last_seen CHECK (last_seen_at >= created_at),
    CONSTRAINT chk_fe_sessions_device_label
        CHECK (device_label IS NULL OR CHAR_LENGTH(TRIM(device_label)) > 0),
    CONSTRAINT chk_fe_sessions_expires
        CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 90 DAY)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
