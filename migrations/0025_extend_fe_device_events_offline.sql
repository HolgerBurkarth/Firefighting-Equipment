-- Offline-Buchungen in der Historie:
-- - occurred_at: Aktionszeit auf dem Handy (UTC), nur bei state_changed; NULL = Serveraktion, die
--   Aktionszeit ist dann created_at (Eingang beim Server). Bestehende Einträge bleiben unverändert.
-- - applied = 0: Buchung nicht übernommen, nur in der Historie (Grund not_applied_reason: older =
--   nicht jünger als der aktuelle Stand, retired = Gerät ausgesondert, location_locked =
--   Ziel-Standort gesperrt). Dann ist nur das Gewünschte gefüllt (to_location_id und/oder to_status,
--   nie retired), from_* bleiben leer: Der Wunsch kann dem aktuellen Stand entsprechen.
-- chk_fe_device_events_state gilt für übernommene Einträge unverändert wie in 0015.
ALTER TABLE fe_device_events
    ADD COLUMN occurred_at DATETIME NULL AFTER comment,
    ADD COLUMN applied TINYINT(1) NOT NULL DEFAULT 1 AFTER occurred_at,
    ADD COLUMN not_applied_reason VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER applied,
    DROP CONSTRAINT chk_fe_device_events_state,
    ADD CONSTRAINT chk_fe_device_events_state CHECK (event_type <> 'state_changed' OR IF(applied = 1, (
        (from_location_id IS NULL) = (to_location_id IS NULL)
        AND (from_status IS NULL) = (to_status IS NULL)
        AND (to_location_id IS NOT NULL OR to_status IS NOT NULL)
        AND (from_location_id IS NULL OR from_location_id <> to_location_id)
        AND (from_status IS NULL OR from_status <> to_status)), (
        from_location_id IS NULL AND from_status IS NULL
        AND (to_location_id IS NOT NULL OR to_status IS NOT NULL)
        AND (to_status IS NULL OR to_status <> 'retired')))),
    ADD CONSTRAINT chk_fe_device_events_applied CHECK (applied IN (0, 1)
        AND (applied = 1) = (not_applied_reason IS NULL)
        AND (applied = 1 OR event_type = 'state_changed')
        AND (not_applied_reason IS NULL OR not_applied_reason IN ('older', 'retired', 'location_locked'))),
    ADD CONSTRAINT chk_fe_device_events_occurred_at CHECK (occurred_at IS NULL OR event_type = 'state_changed');
