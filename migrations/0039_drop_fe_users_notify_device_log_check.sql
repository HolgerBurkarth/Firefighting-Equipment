-- Die Benachrichtigung "Log" bestimmt künftig die Aktion mail.log der Rolle (Code), nicht die
-- Kontoart: alte CHECK (nur admin/superadmin) entfernen, 0040 legt die neue an (Plan Phase 1,
-- Schritt 1.3b).
ALTER TABLE fe_users DROP CONSTRAINT chk_fe_users_notify_device_log;
