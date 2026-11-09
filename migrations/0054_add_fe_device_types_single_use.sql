-- Einmalgerät (Phase 2, Schritt P2.3): Ausrüstungstypen, deren Stücke nach einem Einsatz nicht
-- gewartet, sondern ersetzt werden. Standard 0, bestehende Typen bleiben unverändert. Eine Rolle mit
-- der Einschränkung „Bei Einmalgeräten nur benutzt melden“ (fe_roles.restrict_single_use) darf solche
-- Ausrüstung nur über „Benutzt melden“ buchen.
ALTER TABLE fe_device_types
    ADD COLUMN single_use TINYINT(1) NOT NULL DEFAULT 0 AFTER active,
    ADD CONSTRAINT chk_fe_device_types_single_use CHECK (single_use IN (0, 1));
