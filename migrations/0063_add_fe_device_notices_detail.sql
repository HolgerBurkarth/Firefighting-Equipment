-- Einzelheit zu einem Hinweis (Phase 2, Runde 11): bei stock_below_minimum die unterschrittene Regel, z. B.
-- "Live-Test Wagen: 1 von mindestens 2 Live-Testtyp 2 einsatzbereit". Höchstens 300 Zeichen, sonst NULL.
ALTER TABLE fe_device_notices
    ADD COLUMN detail VARCHAR(300) NULL AFTER stale_confirmed;
