-- Verlauf eines Hinweises (fe_device_notices): jedes Erledigen (resolve, optional mit Notiz) und
-- Wiederöffnen (reopen, ohne Notiz) mit Konto und Zeitpunkt (UTC). Nur INSERT; Notizen bleiben auch
-- nach dem Wiederöffnen erhalten. Hinweis und Konto aus derselben Stadt.
CREATE TABLE fe_device_notice_actions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id  INT UNSIGNED    NOT NULL,
    notice_id  BIGINT UNSIGNED NOT NULL,
    action     VARCHAR(10)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id    INT UNSIGNED    NOT NULL,
    note       VARCHAR(500)    NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fe_device_notice_actions_notice (notice_id, tenant_id, id),
    KEY idx_fe_device_notice_actions_user (user_id, tenant_id),
    CONSTRAINT fk_fe_device_notice_actions_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_device_notice_actions_notice FOREIGN KEY (notice_id, tenant_id)
        REFERENCES fe_device_notices (id, tenant_id),
    CONSTRAINT fk_fe_device_notice_actions_user FOREIGN KEY (user_id, tenant_id)
        REFERENCES fe_users (id, tenant_id),
    CONSTRAINT chk_fe_device_notice_actions_action CHECK (action IN ('resolve', 'reopen')),
    CONSTRAINT chk_fe_device_notice_actions_note CHECK (note IS NULL
        OR (action = 'resolve' AND CHAR_LENGTH(TRIM(note)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
