-- Neuer Status "used" (benutzt) auch in der Historie (Phase 2, Schritt P2.1). Erweitert nur die
-- CHECK-Bedingung, bestehende Zeilen bleiben unverändert.
ALTER TABLE fe_device_events
    DROP CONSTRAINT chk_fe_device_events_status_values,
    ADD CONSTRAINT chk_fe_device_events_status_values CHECK (
        (from_status IS NULL OR from_status IN ('ready', 'maintenance', 'defective', 'used', 'retired'))
        AND (to_status IS NULL OR to_status IN ('ready', 'maintenance', 'defective', 'used', 'retired')));
