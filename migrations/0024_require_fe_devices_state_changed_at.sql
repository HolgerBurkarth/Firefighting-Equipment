-- state_changed_at und state_version (Migration 0023) sind Pflicht. Die Standardwerte gelten nur für
-- Zeilen ohne Angabe (der Code schreibt beide immer ausdrücklich; Tests legen Geräte auch per SQL an):
-- CURRENT_TIMESTAMP ist UTC, weil die Verbindung mit Sitzungs-Zeitzone UTC läuft; 1 = Version eines
-- neuen Geräts. state_version ist nie größer als version.
ALTER TABLE fe_devices
    MODIFY COLUMN state_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY COLUMN state_version INT UNSIGNED NOT NULL DEFAULT 1,
    ADD CONSTRAINT chk_fe_devices_state_version CHECK (state_version BETWEEN 1 AND version);
