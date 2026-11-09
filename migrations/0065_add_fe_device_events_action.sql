-- Verleih dauerhaft speichern (Phase 2, Runde 13): fe_device_events.action nennt die besondere Art einer
-- Buchung (bisher nur 'lent' = Verleih, P2.5). Nur state_changed. Alle bisherigen Zeilen bleiben unverändert
-- (NULL, heißen dann "Umgebucht"); die Bezeichnung hängt nicht mehr von der aktuellen Aufgabe des Standorts ab.
ALTER TABLE fe_device_events
    ADD COLUMN action VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER swap_id,
    ADD CONSTRAINT chk_fe_device_events_action CHECK (action IS NULL OR (action IN ('lent') AND event_type = 'state_changed'));
