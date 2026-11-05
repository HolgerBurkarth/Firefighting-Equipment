-- Neue CHECK der Benachrichtigung "Log": nur 0/1 und nie bei gelöschten Konten. Wer Log-Mails
-- bekommt, prüft der Code über die Aktion mail.log der Rolle (Plan Phase 1, Schritt 1.3b). Jede
-- Zeile, die die alte CHECK (0018) erfüllte, erfüllt auch diese.
ALTER TABLE fe_users ADD CONSTRAINT chk_fe_users_notify_device_log CHECK (notify_device_log IN (0, 1)
    AND (notify_device_log = 0 OR status <> 'deleted'));
