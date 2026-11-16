-- Claude-Zugang je Stadt (T17, Entscheidung Holger 16.11.2026): Nur der Super-Admin schaltet ihn
-- ein; Standard aus. Bisher galt er fest nur für die Test-Stadt (ClaudeAvailability::SLUG), daher
-- schaltet das UPDATE (Freigabe Holger 16.11.2026) genau diese ein; alle anderen Städte bleiben aus.
ALTER TABLE fe_tenants
    ADD COLUMN claude_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER accent_color,
    ADD CONSTRAINT chk_fe_tenants_claude_enabled CHECK (claude_enabled IN (0, 1));

UPDATE fe_tenants SET claude_enabled = 1 WHERE slug = 'test';
