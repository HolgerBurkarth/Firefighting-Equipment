-- SHA-256 des Codes, aus dem die Verbindung entstand (docs/PLAN-MCP.md, Abschnitt 9a): Ein zweites
-- Einlösen desselben Codes findet so die Verbindung und löscht sie (RFC 6749, 4.1.2). NULL nur bei
-- Verbindungen aus der Zeit vor dieser Migration (es gab keine).
ALTER TABLE fe_claude_connections
    ADD COLUMN code_hash BINARY(32) NULL AFTER oauth_client_id,
    ADD UNIQUE KEY uq_fe_claude_connections_code (code_hash);
