-- Kanal beim Erledigen bzw. Wiederöffnen eines Hinweises (wie fe_device_events.channel, Migration
-- 0047): app (Standard, auch für alle bisherigen Einträge) oder claude.
ALTER TABLE fe_device_notice_actions
    ADD COLUMN channel VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'app' AFTER user_id,
    ADD CONSTRAINT chk_fe_device_notice_actions_channel CHECK (channel IN ('app', 'claude'));
