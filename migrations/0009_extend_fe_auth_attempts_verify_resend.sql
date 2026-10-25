-- Neue Aktion verify_resend: Bestätigungslink neu anfordern. Gezählt wird je E-Mail-Adresse, daher
-- ist email_hash hier Pflicht (wie bei register und password_reset; die bestehende CHECK-Bedingung
-- für email_hash erlaubt NULL weiterhin nur bei verify_email und reset_confirm).
ALTER TABLE fe_auth_attempts
    DROP CONSTRAINT chk_fe_auth_attempts_action,
    ADD CONSTRAINT chk_fe_auth_attempts_action CHECK (action IN
        ('login', 'register', 'password_reset', 'reset_confirm', 'verify_email', 'verify_resend'));
