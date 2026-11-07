-- Kanal einer Änderung im Verlauf (docs/PLAN-MCP.md, Abschnitt 6, Schritt M6): app (App bzw. REST-API,
-- Standard, auch für alle bisherigen Einträge) oder claude (über ein Werkzeug des Claude-Zugangs).
ALTER TABLE fe_device_events
    ADD COLUMN channel VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'app' AFTER user_id,
    ADD CONSTRAINT chk_fe_device_events_channel CHECK (channel IN ('app', 'claude'));
