-- Gemeinsamer Zeitstempel von Standort und Status (Offline-Buchungen, "der Letzte gewinnt"):
-- state_changed_at = Aktionszeit der Änderung, die den aktuellen Stand bestimmt (UTC);
-- state_version = Wert von version nach dieser Änderung. Hat sich der Stand seit der Version, die
-- das Handy kannte, nicht geändert (state_version <= erwartete Version), ist eine Offline-Buchung
-- trotz veralteter Version kein Konflikt (z. B. nach einer Änderung von Stammdaten).
-- Erst NULL erlaubt; Startwerte unten, NOT NULL in Migration 0024.
ALTER TABLE fe_devices
    ADD COLUMN state_changed_at DATETIME NULL AFTER version,
    ADD COLUMN state_version INT UNSIGNED NULL AFTER state_changed_at;

-- Startwerte: Zeitpunkt des neuesten Ereignisses, das Standort oder Status gesetzt hat (Anlegen,
-- Import, Umbuchen/Status, Aussondern, Wiederaufnehmen; bisher immer die Serverzeit), ohne solches
-- Ereignis der Zeitpunkt des Anlegens. state_version = aktuelle Version (vorsichtig: Jede ältere
-- Version gilt als möglicher Konflikt und wird über die Zeit entschieden).
UPDATE fe_devices d
SET d.state_changed_at = COALESCE((
        SELECT e.created_at FROM fe_device_events e
        WHERE e.device_id = d.id AND e.tenant_id = d.tenant_id
            AND e.event_type IN ('created', 'imported', 'state_changed')
        ORDER BY e.id DESC LIMIT 1
    ), d.created_at),
    d.state_version = d.version;
