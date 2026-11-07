-- Claude-Zugang je Konto (docs/MCP-KONZEPT.md, Abschnitt 2): off (Standard, keine Verbindung),
-- read (nur Ansehen), write (Rechte der Rolle). Super-Admins und gelöschte Konten haben nie
-- Zugang. Bestehende Konten erhalten den Standard off.
ALTER TABLE fe_users
    ADD COLUMN claude_access VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'off'
        AFTER notify_device_log,
    ADD CONSTRAINT chk_fe_users_claude_access CHECK (claude_access IN ('off', 'read', 'write')
        AND (claude_access = 'off' OR (role <> 'superadmin' AND status <> 'deleted')));
