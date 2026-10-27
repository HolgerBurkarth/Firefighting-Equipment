-- Quittungen der Buchungen mit client_ref (POST devices/{id}/state): Dieselbe Buchung erneut gesendet
-- (gleiche Stadt und client_ref) wirkt kein zweites Mal; die Antwort wiederholt das gespeicherte
-- Ergebnis. client_ref ist eine UUID v4 (klein geschrieben, ascii_bin), eindeutig je Stadt.
-- request_hash: SHA-256 über die übrigen Felder der Buchung; dieselbe client_ref mit anderem Inhalt,
-- anderem Gerät oder anderem Konto ist ein Fehler (client_ref_conflict).
-- outcome/reason: Ergebnis (applied = übernommen, history_only = nur Historie, not_applied = nicht
-- übernommen, unchanged = keine Änderung) mit Grund (overwritten = neuerer Stand überschrieben,
-- older = Buchung älter als der aktuelle Stand, retired = Gerät ausgesondert, location_locked =
-- Ziel-Standort gesperrt). stale_confirmed = alte Buchung nach Rückfrage gesendet. event_id = der
-- Eintrag der Historie (bei unchanged keiner). Nie geändert oder gelöscht (Ausnahme: "Gerätedaten
-- zurücksetzen").
CREATE TABLE fe_device_bookings (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED    NOT NULL,
    client_ref      CHAR(36)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id         INT UNSIGNED    NOT NULL,
    device_id       INT UNSIGNED    NOT NULL,
    request_hash    BINARY(32)      NOT NULL,
    outcome         VARCHAR(20)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason          VARCHAR(20)     CHARACTER SET ascii COLLATE ascii_bin NULL,
    stale_confirmed TINYINT(1)      NOT NULL DEFAULT 0,
    event_id        BIGINT UNSIGNED NULL,
    received_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_device_bookings_tenant_ref (tenant_id, client_ref),
    UNIQUE KEY uq_fe_device_bookings_event (event_id),
    KEY idx_fe_device_bookings_device (device_id, tenant_id),
    KEY idx_fe_device_bookings_user (user_id, tenant_id),
    CONSTRAINT fk_fe_device_bookings_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_device_bookings_device FOREIGN KEY (device_id, tenant_id)
        REFERENCES fe_devices (id, tenant_id),
    CONSTRAINT fk_fe_device_bookings_user FOREIGN KEY (user_id, tenant_id)
        REFERENCES fe_users (id, tenant_id),
    CONSTRAINT fk_fe_device_bookings_event FOREIGN KEY (event_id) REFERENCES fe_device_events (id),
    CONSTRAINT chk_fe_device_bookings_client_ref CHECK (client_ref
        REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z'),
    -- <=> statt =: Eine CHECK-Bedingung, die NULL ergibt, gilt als erfüllt (reason NULL).
    CONSTRAINT chk_fe_device_bookings_outcome CHECK (
        (outcome = 'applied' AND (reason IS NULL OR reason <=> 'overwritten') AND event_id IS NOT NULL)
        OR (outcome = 'history_only' AND reason <=> 'older' AND event_id IS NOT NULL)
        OR (outcome = 'not_applied' AND (reason <=> 'retired' OR reason <=> 'location_locked') AND event_id IS NOT NULL)
        OR (outcome = 'unchanged' AND reason IS NULL AND event_id IS NULL)),
    CONSTRAINT chk_fe_device_bookings_stale_confirmed CHECK (stale_confirmed IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
