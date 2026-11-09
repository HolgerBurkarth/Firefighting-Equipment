-- Neue Arten von Hinweisen (Phase 2, Schritt P2.8): stock_below_minimum (eine Buchung hat den Sollbestand
-- mit der Aktion "stock.override" unterschritten) und swap_replacement_moved (Tausch, dessen Ersatz
-- inzwischen woanders war; kommt mit P2.9). Erweitert nur die CHECK-Bedingung.
ALTER TABLE fe_device_notices
    DROP CONSTRAINT chk_fe_device_notices_kind,
    ADD CONSTRAINT chk_fe_device_notices_kind CHECK (kind IN ('overwritten', 'history_only',
        'not_applied_retired', 'not_applied_location_locked', 'stale_confirmed',
        'stock_below_minimum', 'swap_replacement_moved'));
