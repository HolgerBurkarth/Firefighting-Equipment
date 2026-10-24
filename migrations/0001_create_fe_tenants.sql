-- Städte (Mandanten). Das Kürzel ist nach dem Anlegen unveränderlich; das stellt der Code sicher
-- (keine Trigger erlaubt). Die Formatprüfung läuft mit utf8mb4_bin, damit REGEXP Groß- und
-- Kleinschreibung unterscheidet; \z statt $, weil $ auch vor einem abschließenden Zeilenumbruch passt.
CREATE TABLE fe_tenants (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug       VARCHAR(50)  NOT NULL,
    name       VARCHAR(100) NOT NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_tenants_slug (slug),
    CONSTRAINT chk_fe_tenants_slug_format
        CHECK (slug COLLATE utf8mb4_bin REGEXP '^[a-z0-9]+(-[a-z0-9]+)*\\z'),
    CONSTRAINT chk_fe_tenants_slug_reserved CHECK (slug <> 'service'),
    CONSTRAINT chk_fe_tenants_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
    CONSTRAINT chk_fe_tenants_active CHECK (active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
