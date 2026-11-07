-- Neue Aktion oauth_register: Registrierung eines OAuth-Clients (Claude, docs/PLAN-MCP.md,
-- Abschnitt 5). Gezählt wird nur je IP-Präfix, daher ohne E-Mail. Eine einzige ALTER-TABLE-Anweisung
-- ersetzt beide CHECK-Bedingungen (wie 0008-0010).
ALTER TABLE fe_auth_attempts
    DROP CONSTRAINT chk_fe_auth_attempts_action,
    ADD CONSTRAINT chk_fe_auth_attempts_action CHECK (action IN ('login', 'register', 'password_reset',
        'reset_confirm', 'verify_email', 'verify_resend', 'invitation_accept', 'oauth_register')),
    DROP CONSTRAINT chk_fe_auth_attempts_email_hash,
    ADD CONSTRAINT chk_fe_auth_attempts_email_hash CHECK (IF(email_hash IS NULL,
        action IN ('verify_email', 'reset_confirm', 'invitation_accept', 'oauth_register'),
        OCTET_LENGTH(email_hash) = 32));
