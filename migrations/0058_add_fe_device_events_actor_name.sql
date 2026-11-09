-- Rückgabe ohne Anmeldung (Phase 2, Schritt P2.6, Entscheidung G6): Das Ereignis hat kein Konto
-- (user_id NULL), sondern den Namen des Überbringers (actor_name, bis 100 Zeichen). Das gilt nur für
-- state_changed: genau dann ist actor_name gesetzt, wenn user_id fehlt. Alle bisherigen Zeilen behalten
-- ihr Konto.
ALTER TABLE fe_device_events
    MODIFY COLUMN user_id INT UNSIGNED NULL,
    ADD COLUMN actor_name VARCHAR(100) NULL AFTER user_id,
    ADD CONSTRAINT chk_fe_device_events_actor CHECK (
        (user_id IS NOT NULL AND actor_name IS NULL)
        OR (user_id IS NULL AND event_type = 'state_changed' AND actor_name IS NOT NULL
            AND CHAR_LENGTH(TRIM(actor_name)) > 0));
