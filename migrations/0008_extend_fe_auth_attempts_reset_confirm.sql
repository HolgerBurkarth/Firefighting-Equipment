-- Neue Aktion reset_confirm: Einlösen eines Links zum Zurücksetzen des Passworts. Ein ungültiger
-- Link gehört zu keiner E-Mail-Adresse, daher darf email_hash wie bei verify_email fehlen.
-- Eine einzige ALTER-TABLE-Anweisung ersetzt beide CHECK-Bedingungen.
ALTER TABLE fe_auth_attempts
    DROP CONSTRAINT chk_fe_auth_attempts_action,
    ADD CONSTRAINT chk_fe_auth_attempts_action
        CHECK (action IN ('login', 'register', 'password_reset', 'reset_confirm', 'verify_email')),
    DROP CONSTRAINT chk_fe_auth_attempts_email_hash,
    ADD CONSTRAINT chk_fe_auth_attempts_email_hash CHECK (
        IF(email_hash IS NULL, action IN ('verify_email', 'reset_confirm'), OCTET_LENGTH(email_hash) = 32));
