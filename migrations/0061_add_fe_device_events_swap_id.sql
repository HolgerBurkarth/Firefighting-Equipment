-- Tausch (Phase 2, Schritt P2.8): Beide Buchungen eines Tauschs (die Ausrüstung, die herausgeht, und der
-- Ersatz) tragen dieselbe Kennung swap_id (32 Hex-Zeichen). Nur bei state_changed. Alle bisherigen Zeilen
-- bleiben unverändert (NULL).
ALTER TABLE fe_device_events
    ADD COLUMN swap_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER actor_name,
    ADD CONSTRAINT chk_fe_device_events_swap_id CHECK (swap_id IS NULL OR event_type = 'state_changed'),
    ADD KEY idx_fe_device_events_swap (tenant_id, swap_id);
