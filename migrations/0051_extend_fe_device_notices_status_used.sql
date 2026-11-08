-- Neuer Status "used" (benutzt) auch in den Hinweisen (Phase 2, Schritt P2.1). Erweitert nur die
-- CHECK-Bedingungen, bestehende Zeilen bleiben unverändert.
ALTER TABLE fe_device_notices
    DROP CONSTRAINT chk_fe_device_notices_requested,
    ADD CONSTRAINT chk_fe_device_notices_requested CHECK (
        (requested_location_id IS NOT NULL OR requested_status IS NOT NULL)
        AND (requested_status IS NULL OR requested_status IN ('ready', 'maintenance', 'defective', 'used'))),
    DROP CONSTRAINT chk_fe_device_notices_other_status,
    ADD CONSTRAINT chk_fe_device_notices_other_status
        CHECK (other_status IN ('ready', 'maintenance', 'defective', 'used', 'retired'));
