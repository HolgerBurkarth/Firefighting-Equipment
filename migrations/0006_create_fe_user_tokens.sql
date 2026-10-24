-- Einmal-Links (nur SHA-256 des Tokens). Einlösen = Zeile löschen; je Benutzer und Zweck
-- höchstens ein offener Link. Die Höchstgültigkeit je Zweck prüft die Datenbank. purpose ist
-- ascii_bin, damit IN (...) Groß- und Kleinschreibung unterscheidet.
CREATE TABLE fe_user_tokens (
    id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED  NOT NULL,
    purpose    VARCHAR(20)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash VARBINARY(32) NOT NULL,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_user_tokens_token_hash (token_hash),
    UNIQUE KEY uq_fe_user_tokens_user_purpose (user_id, purpose),
    CONSTRAINT fk_fe_user_tokens_user FOREIGN KEY (user_id) REFERENCES fe_users (id),
    CONSTRAINT chk_fe_user_tokens_purpose
        CHECK (purpose IN ('verify_email', 'set_password', 'reset_password')),
    CONSTRAINT chk_fe_user_tokens_token_hash CHECK (OCTET_LENGTH(token_hash) = 32),
    CONSTRAINT chk_fe_user_tokens_expires CHECK (expires_at > created_at AND (
        (purpose = 'reset_password' AND expires_at <= created_at + INTERVAL 1 HOUR)
        OR (purpose IN ('verify_email', 'set_password')
            AND expires_at <= created_at + INTERVAL 24 HOUR)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
