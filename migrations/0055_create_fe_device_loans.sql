-- Verleih (Phase 2, Schritt P2.5, docs/PLAN-PHASE-2.md): je verliehener Ausrüstung eine Zeile. Der Verleih
-- bucht die Ausrüstung auf einen Standort mit der Aufgabe "Ausgeliehen an" (state_changed im Verlauf);
-- diese Tabelle hält Ansprechpartner, "Rückgabe bis" und Kommentar. returned_at ist gesetzt, sobald die
-- Ausrüstung zurück ist (Rückgabe, P2.6) oder den Leihnehmer anders verlassen hat. Höchstens ein offener
-- Verleih je Ausrüstung (open_device_key + UNIQUE). Keine Zeile wird gelöscht (Ausnahme "Gerätedaten
-- zurücksetzen").
CREATE TABLE fe_device_loans (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id            INT UNSIGNED    NOT NULL,
    device_id            INT UNSIGNED    NOT NULL,
    borrower_location_id INT UNSIGNED    NOT NULL,
    contact              VARCHAR(100)    NULL,
    due_back_on          DATE            NULL,
    comment              VARCHAR(500)    NULL,
    lent_by              INT UNSIGNED    NOT NULL,
    lent_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    returned_at          DATETIME        NULL,
    open_device_key      INT UNSIGNED AS (IF(returned_at IS NULL, device_id, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_device_loans_open_device (open_device_key),
    KEY idx_fe_device_loans_tenant_open (tenant_id, returned_at, borrower_location_id),
    KEY idx_fe_device_loans_device (device_id, tenant_id),
    KEY idx_fe_device_loans_location (borrower_location_id, tenant_id),
    KEY idx_fe_device_loans_user (lent_by, tenant_id),
    CONSTRAINT fk_fe_device_loans_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_device_loans_device FOREIGN KEY (device_id, tenant_id)
        REFERENCES fe_devices (id, tenant_id),
    CONSTRAINT fk_fe_device_loans_location FOREIGN KEY (borrower_location_id, tenant_id)
        REFERENCES fe_locations (id, tenant_id),
    CONSTRAINT fk_fe_device_loans_user FOREIGN KEY (lent_by, tenant_id)
        REFERENCES fe_users (id, tenant_id),
    CONSTRAINT chk_fe_device_loans_contact CHECK (contact IS NULL OR CHAR_LENGTH(TRIM(contact)) > 0),
    CONSTRAINT chk_fe_device_loans_comment CHECK (comment IS NULL OR CHAR_LENGTH(TRIM(comment)) > 0),
    CONSTRAINT chk_fe_device_loans_due CHECK (due_back_on IS NULL OR due_back_on >= '1950-01-01')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
