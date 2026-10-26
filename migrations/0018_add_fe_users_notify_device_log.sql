-- Benachrichtigung "Log" (Mail bei jedem Vorgang der Geräte-Historie), je Konto ein- und
-- ausschaltbar, Standard aus. Nur für Admins und Super-Admins; ein herabgestuftes oder gelöschtes
-- Konto muss die Einstellung verlieren.
ALTER TABLE fe_users
    ADD COLUMN notify_device_log TINYINT(1) NOT NULL DEFAULT 0 AFTER protected,
    ADD CONSTRAINT chk_fe_users_notify_device_log CHECK (notify_device_log IN (0, 1)
        AND (notify_device_log = 0 OR (role IN ('superadmin', 'admin') AND status <> 'deleted')));
