-- Neuer Status "used" (benutzt): Phase 2, Schritt P2.1 (docs/PLAN-PHASE-2.md). Erweitert nur die
-- CHECK-Bedingung, bestehende Zeilen bleiben unverändert.
ALTER TABLE fe_devices
    DROP CONSTRAINT chk_fe_devices_status,
    ADD CONSTRAINT chk_fe_devices_status CHECK (status IN ('ready', 'maintenance', 'defective', 'used', 'retired'));
