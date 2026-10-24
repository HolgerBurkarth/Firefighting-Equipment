-- Versuche für Anmeldung, Registrierung, Passwort zurücksetzen und E-Mail-Bestätigung (Bremse gegen
-- Durchprobieren). E-Mail nur als SHA-256 (auch für unbekannte Adressen; nur bei verify_email darf
-- sie fehlen), IP nur gekürzt: IPv4 /24 (4 Byte, letztes 0), IPv6 /48 (16 Byte, letzte 10 Byte 0).
-- tenant_id NULL = Bereich service. action ist ascii_bin, damit IN (...) Groß- und Kleinschreibung
-- unterscheidet.
CREATE TABLE fe_auth_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action       VARCHAR(20)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id    INT UNSIGNED    NULL,
    email_hash   VARBINARY(32)   NULL,
    ip_prefix    VARBINARY(16)   NOT NULL,
    succeeded    TINYINT(1)      NOT NULL,
    attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fe_auth_attempts_email (action, tenant_id, email_hash, attempted_at),
    KEY idx_fe_auth_attempts_ip (ip_prefix, attempted_at),
    KEY idx_fe_auth_attempts_tenant (tenant_id),
    KEY idx_fe_auth_attempts_attempted (attempted_at),
    CONSTRAINT fk_fe_auth_attempts_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT chk_fe_auth_attempts_action
        CHECK (action IN ('login', 'register', 'password_reset', 'verify_email')),
    CONSTRAINT chk_fe_auth_attempts_email_hash CHECK (
        IF(email_hash IS NULL, action = 'verify_email', OCTET_LENGTH(email_hash) = 32)),
    CONSTRAINT chk_fe_auth_attempts_ip_prefix CHECK (
        (OCTET_LENGTH(ip_prefix) = 4 AND SUBSTRING(ip_prefix, 4) = X'00')
        OR (OCTET_LENGTH(ip_prefix) = 16 AND SUBSTRING(ip_prefix, 7) = X'00000000000000000000')),
    CONSTRAINT chk_fe_auth_attempts_succeeded CHECK (succeeded IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
