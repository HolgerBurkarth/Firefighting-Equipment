-- Neue Aktion invitation_accept: Einladung als Admin annehmen (Link set_password einlösen). Ein
-- ungültiger Link gehört zu keiner E-Mail-Adresse, daher darf email_hash wie bei verify_email und
-- reset_confirm fehlen. Eine einzige ALTER-TABLE-Anweisung ersetzt beide CHECK-Bedingungen.
ALTER TABLE fe_auth_attempts
    DROP CONSTRAINT chk_fe_auth_attempts_action,
    ADD CONSTRAINT chk_fe_auth_attempts_action CHECK (action IN ('login', 'register', 'password_reset',
        'reset_confirm', 'verify_email', 'verify_resend', 'invitation_accept')),
    DROP CONSTRAINT chk_fe_auth_attempts_email_hash,
    ADD CONSTRAINT chk_fe_auth_attempts_email_hash CHECK (IF(email_hash IS NULL,
        action IN ('verify_email', 'reset_confirm', 'invitation_accept'), OCTET_LENGTH(email_hash) = 32));
