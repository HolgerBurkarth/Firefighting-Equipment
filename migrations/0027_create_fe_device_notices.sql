-- Hinweise an die Admins einer Stadt zu Offline-Buchungen, die nicht einfach übernommen wurden.
-- Ein Hinweis je Buchung (booking_id eindeutig). kind:
-- - overwritten: übernommen, ein anderer (älterer) Stand wurde überschrieben;
-- - history_only: nicht übernommen, ein neuerer Stand liegt vor (nur Historie);
-- - not_applied_retired / not_applied_location_locked: nicht übernommen, Gerät ausgesondert bzw.
--   Ziel-Standort gesperrt;
-- - stale_confirmed: sonst unauffällig, aber alte Buchung nach Rückfrage gesendet.
-- stale_confirmed = 1 auch bei den anderen Arten, wenn die Buchung nach Rückfrage gesendet wurde.
-- Buchung: user_id, occurred_at (Aktionszeit), gewünschter Standort und/oder Status. Anderer Stand
-- (other_*): der Stand beim Eingang der Buchung (bei overwritten der überschriebene) mit
-- Aktionszeit und dem Konto, das ihn gesetzt hat (NULL, wenn keines bekannt ist).
-- status open/resolved; der Verlauf (erledigt, wieder geöffnet) steht in fe_device_notice_actions
-- (Migration 0028). Hinweise werden nie automatisch erledigt und nie gelöscht (Ausnahme:
-- "Gerätedaten zurücksetzen").
CREATE TABLE fe_device_notices (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id             INT UNSIGNED    NOT NULL,
    device_id             INT UNSIGNED    NOT NULL,
    booking_id            BIGINT UNSIGNED NOT NULL,
    event_id              BIGINT UNSIGNED NULL,
    kind                  VARCHAR(30)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stale_confirmed       TINYINT(1)      NOT NULL DEFAULT 0,
    user_id               INT UNSIGNED    NOT NULL,
    occurred_at           DATETIME        NOT NULL,
    requested_location_id INT UNSIGNED    NULL,
    requested_status      VARCHAR(12)     CHARACTER SET ascii COLLATE ascii_bin NULL,
    other_user_id         INT UNSIGNED    NULL,
    other_occurred_at     DATETIME        NOT NULL,
    other_location_id     INT UNSIGNED    NOT NULL,
    other_status          VARCHAR(12)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status                VARCHAR(10)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_device_notices_booking (booking_id),
    UNIQUE KEY uq_fe_device_notices_id_tenant (id, tenant_id),
    KEY idx_fe_device_notices_tenant_status (tenant_id, status, id),
    KEY idx_fe_device_notices_device (device_id, tenant_id),
    KEY idx_fe_device_notices_user (user_id, tenant_id),
    KEY idx_fe_device_notices_other_user (other_user_id, tenant_id),
    KEY idx_fe_device_notices_requested_location (requested_location_id, tenant_id),
    KEY idx_fe_device_notices_other_location (other_location_id, tenant_id),
    CONSTRAINT fk_fe_device_notices_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_device_notices_device FOREIGN KEY (device_id, tenant_id)
        REFERENCES fe_devices (id, tenant_id),
    CONSTRAINT fk_fe_device_notices_booking FOREIGN KEY (booking_id) REFERENCES fe_device_bookings (id),
    CONSTRAINT fk_fe_device_notices_event FOREIGN KEY (event_id) REFERENCES fe_device_events (id),
    CONSTRAINT fk_fe_device_notices_user FOREIGN KEY (user_id, tenant_id)
        REFERENCES fe_users (id, tenant_id),
    CONSTRAINT fk_fe_device_notices_other_user FOREIGN KEY (other_user_id, tenant_id)
        REFERENCES fe_users (id, tenant_id),
    CONSTRAINT fk_fe_device_notices_requested_location FOREIGN KEY (requested_location_id, tenant_id)
        REFERENCES fe_locations (id, tenant_id),
    CONSTRAINT fk_fe_device_notices_other_location FOREIGN KEY (other_location_id, tenant_id)
        REFERENCES fe_locations (id, tenant_id),
    CONSTRAINT chk_fe_device_notices_kind CHECK (kind IN ('overwritten', 'history_only',
        'not_applied_retired', 'not_applied_location_locked', 'stale_confirmed')),
    CONSTRAINT chk_fe_device_notices_stale_confirmed CHECK (stale_confirmed IN (0, 1)
        AND (kind <> 'stale_confirmed' OR stale_confirmed = 1)),
    CONSTRAINT chk_fe_device_notices_requested CHECK (
        (requested_location_id IS NOT NULL OR requested_status IS NOT NULL)
        AND (requested_status IS NULL OR requested_status IN ('ready', 'maintenance', 'defective'))),
    CONSTRAINT chk_fe_device_notices_other_status
        CHECK (other_status IN ('ready', 'maintenance', 'defective', 'retired')),
    CONSTRAINT chk_fe_device_notices_status CHECK (status IN ('open', 'resolved'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
