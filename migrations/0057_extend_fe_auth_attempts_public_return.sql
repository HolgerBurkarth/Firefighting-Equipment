-- Neue Aktion public_return: Rückgabe ohne Anmeldung (Phase 2, Schritt P2.6). Ein ungültiger Schlüssel
-- oder Code gehört zu keiner E-Mail-Adresse, daher darf email_hash fehlen. Eine einzige ALTER-TABLE-
-- Anweisung ersetzt beide CHECK-Bedingungen (wie 0045).
ALTER TABLE fe_auth_attempts
    DROP CONSTRAINT chk_fe_auth_attempts_action,
    ADD CONSTRAINT chk_fe_auth_attempts_action CHECK (action IN ('login', 'register', 'password_reset',
        'reset_confirm', 'verify_email', 'verify_resend', 'invitation_accept', 'oauth_register',
        'public_return')),
    DROP CONSTRAINT chk_fe_auth_attempts_email_hash,
    ADD CONSTRAINT chk_fe_auth_attempts_email_hash CHECK (IF(email_hash IS NULL,
        action IN ('verify_email', 'reset_confirm', 'invitation_accept', 'oauth_register', 'public_return'),
        OCTET_LENGTH(email_hash) = 32));
