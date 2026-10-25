-- Standorte je Stadt: Fahrzeug, Raum/Lager, Extern; höchstens zwei Ebenen. Nie gelöscht, nur gesperrt
-- (active = 0). Ohne Trigger erzwungen über den Fremdschlüssel auf den übergeordneten Standort:
-- - Ebene: level ist 1 (ohne übergeordneten Standort) oder 2; ein Unterstandort verweist mit
--   parent_level = 1 auf (id, tenant_id, level), kann also nur unter einem Standort der Ebene 1 liegen
--   (und nie unter sich selbst). Hat ein Standort Unterstandorte, kann er nicht Ebene 2 werden.
-- - Art: kind gehört zum selben Fremdschlüssel; ein Unterstandort hat immer die Art seines
--   übergeordneten Standorts, und dessen Art lässt sich nicht ändern, solange er Unterstandorte hat.
-- - Stadt: tenant_id gehört ebenfalls dazu (kein Bezug über Stadtgrenzen).
-- Der Name ist je Stadt und übergeordnetem Standort eindeutig (parent_key 0 = Ebene 1;
-- utf8mb4_unicode_ci: ohne Groß- und Kleinschreibung).
-- chk_fe_locations_level verwendet <=>: Mit parent_level NULL wäre "= 1" NULL (CHECK erfüllt) und
-- der Fremdschlüssel würde nicht geprüft.
CREATE TABLE fe_locations (
    id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    tenant_id    INT UNSIGNED     NOT NULL,
    kind         VARCHAR(10)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name         VARCHAR(100)     NOT NULL,
    parent_id    INT UNSIGNED     NULL,
    parent_key   INT UNSIGNED     AS (IFNULL(parent_id, 0)) STORED,
    level        TINYINT UNSIGNED NOT NULL,
    parent_level TINYINT UNSIGNED NULL,
    active       TINYINT(1)       NOT NULL DEFAULT 1,
    created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_locations_id_tenant (id, tenant_id),
    UNIQUE KEY uq_fe_locations_id_tenant_level_kind (id, tenant_id, level, kind),
    UNIQUE KEY uq_fe_locations_tenant_parent_name (tenant_id, parent_key, name),
    KEY idx_fe_locations_parent (parent_id, tenant_id, parent_level, kind),
    CONSTRAINT fk_fe_locations_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_locations_parent FOREIGN KEY (parent_id, tenant_id, parent_level, kind)
        REFERENCES fe_locations (id, tenant_id, level, kind),
    CONSTRAINT chk_fe_locations_kind CHECK (kind IN ('vehicle', 'room', 'external')),
    CONSTRAINT chk_fe_locations_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
    CONSTRAINT chk_fe_locations_level CHECK (IF(parent_id IS NULL,
        level = 1 AND parent_level IS NULL, level = 2 AND parent_level <=> 1)),
    CONSTRAINT chk_fe_locations_active CHECK (active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
