-- Historie der Geräte, unveränderlich: Der Code schreibt nur INSERT, nie UPDATE oder DELETE (ohne
-- Trigger nicht erzwingbar). Gerät, Benutzer und Standorte nur aus derselben Stadt (zusammengesetzte
-- Fremdschlüssel; der Super-Admin hat keine Stadt). Anonymisierte Konten bleiben erhalten. Da jedes
-- Gerät ein Ereignis created oder imported hat, verhindert fk_fe_device_events_device das Löschen.
-- Ereignisse (event_type, ascii_bin wie alle festen Werte):
-- - created, imported: Ziel-Standort und Ziel-Status (nicht ausgesondert).
-- - state_changed: Umbuchung und/oder Statusänderung in EINEM Eintrag; je Paar beide Werte oder keiner,
--   mindestens ein Paar, jeweils verschieden. Aussondern und Wiederaufnehmen gehören hierher.
-- - due_changed: nächste Prüfung fällig, alt und neu (je auch leer), verschieden.
-- - field_changed: Stammdaten, ein Eintrag je Feld, alter und neuer Wert als Text (beim Gerätetyp der
--   Name zum Zeitpunkt der Änderung). old_value/new_value sind utf8mb4_bin, damit z. B. "ma-01" ->
--   "MA-01" als Änderung gilt.
CREATE TABLE fe_device_events (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id        INT UNSIGNED    NOT NULL,
    device_id        INT UNSIGNED    NOT NULL,
    event_type       VARCHAR(20)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id          INT UNSIGNED    NOT NULL,
    from_location_id INT UNSIGNED    NULL,
    to_location_id   INT UNSIGNED    NULL,
    from_status      VARCHAR(12)     CHARACTER SET ascii COLLATE ascii_bin NULL,
    to_status        VARCHAR(12)     CHARACTER SET ascii COLLATE ascii_bin NULL,
    from_due         DATE            NULL,
    to_due           DATE            NULL,
    field            VARCHAR(20)     CHARACTER SET ascii COLLATE ascii_bin NULL,
    old_value        VARCHAR(1000)   COLLATE utf8mb4_bin NULL,
    new_value        VARCHAR(1000)   COLLATE utf8mb4_bin NULL,
    comment          VARCHAR(500)    NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fe_device_events_device (device_id, tenant_id, id),
    KEY idx_fe_device_events_tenant (tenant_id, created_at),
    KEY idx_fe_device_events_user (user_id, tenant_id),
    KEY idx_fe_device_events_from_location (from_location_id, tenant_id),
    KEY idx_fe_device_events_to_location (to_location_id, tenant_id),
    CONSTRAINT fk_fe_device_events_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_device_events_device FOREIGN KEY (device_id, tenant_id)
        REFERENCES fe_devices (id, tenant_id),
    CONSTRAINT fk_fe_device_events_user FOREIGN KEY (user_id, tenant_id)
        REFERENCES fe_users (id, tenant_id),
    CONSTRAINT fk_fe_device_events_from_location FOREIGN KEY (from_location_id, tenant_id)
        REFERENCES fe_locations (id, tenant_id),
    CONSTRAINT fk_fe_device_events_to_location FOREIGN KEY (to_location_id, tenant_id)
        REFERENCES fe_locations (id, tenant_id),
    CONSTRAINT chk_fe_device_events_type CHECK (event_type IN
        ('created', 'imported', 'state_changed', 'due_changed', 'field_changed')),
    CONSTRAINT chk_fe_device_events_status_values CHECK (
        (from_status IS NULL OR from_status IN ('ready', 'maintenance', 'defective', 'retired'))
        AND (to_status IS NULL OR to_status IN ('ready', 'maintenance', 'defective', 'retired'))),
    CONSTRAINT chk_fe_device_events_created CHECK (event_type NOT IN ('created', 'imported') OR (
        from_location_id IS NULL AND from_status IS NULL
        AND to_location_id IS NOT NULL AND to_status IS NOT NULL AND to_status <> 'retired')),
    CONSTRAINT chk_fe_device_events_state CHECK (event_type <> 'state_changed' OR (
        (from_location_id IS NULL) = (to_location_id IS NULL)
        AND (from_status IS NULL) = (to_status IS NULL)
        AND (to_location_id IS NOT NULL OR to_status IS NOT NULL)
        AND (from_location_id IS NULL OR from_location_id <> to_location_id)
        AND (from_status IS NULL OR from_status <> to_status))),
    CONSTRAINT chk_fe_device_events_no_state CHECK (
        event_type IN ('created', 'imported', 'state_changed') OR (from_location_id IS NULL
        AND to_location_id IS NULL AND from_status IS NULL AND to_status IS NULL)),
    CONSTRAINT chk_fe_device_events_due CHECK (IF(event_type = 'due_changed',
        NOT (from_due <=> to_due), from_due IS NULL AND to_due IS NULL)),
    CONSTRAINT chk_fe_device_events_due_range CHECK (
        (from_due IS NULL OR from_due BETWEEN '1950-01-01' AND '2099-12-31')
        AND (to_due IS NULL OR to_due BETWEEN '1950-01-01' AND '2099-12-31')),
    CONSTRAINT chk_fe_device_events_field CHECK (IF(event_type = 'field_changed',
        field IS NOT NULL AND field IN ('identifier', 'device_type', 'serial_number',
            'manufacturer', 'model', 'procured_on', 'note') AND NOT (old_value <=> new_value),
        field IS NULL AND old_value IS NULL AND new_value IS NULL)),
    CONSTRAINT chk_fe_device_events_comment
        CHECK (comment IS NULL OR CHAR_LENGTH(TRIM(comment)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
