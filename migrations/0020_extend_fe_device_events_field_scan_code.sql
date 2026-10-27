-- Stammdatenfeld scan_code in der Historie (Ereignis field_changed); sonst unverändert wie in 0015.
ALTER TABLE fe_device_events
    DROP CONSTRAINT chk_fe_device_events_field,
    ADD CONSTRAINT chk_fe_device_events_field CHECK (IF(event_type = 'field_changed',
        field IS NOT NULL AND field IN ('identifier', 'scan_code', 'device_type', 'serial_number',
            'manufacturer', 'model', 'procured_on', 'note') AND NOT (old_value <=> new_value),
        field IS NULL AND old_value IS NULL AND new_value IS NULL));
