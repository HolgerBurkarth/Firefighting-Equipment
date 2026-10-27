-- Akzentfarbe einer Stadt (Erscheinungsbild), NULL = Standardfarbe (AccentColor::DEFAULT).
-- Gespeichert immer als #rrggbb in Kleinbuchstaben (ascii_bin, damit REGEXP Groß- und
-- Kleinschreibung unterscheidet; \z statt $, weil $ auch vor einem abschließenden Zeilenumbruch
-- passt). Den Kontrast zu weißer Schrift (mindestens 4,5:1) prüft nur der Code.
ALTER TABLE fe_tenants
    ADD COLUMN accent_color CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER active,
    ADD CONSTRAINT chk_fe_tenants_accent_color
        CHECK (accent_color IS NULL OR accent_color REGEXP '^#[0-9a-f]{6}\\z');
