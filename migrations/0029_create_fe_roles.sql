-- Rollen je Stadt (Phase 1, docs/PLAN-PHASE-1-ROLLEN.md). Eine Rolle bündelt Bereiche mit Stufe
-- (fe_role_areas), Aktionen (fe_role_actions) und Datenfilter (fe_role_locations,
-- fe_role_device_types). system_key kennzeichnet die beiden Standard-Rollen jeder Stadt: admin
-- (alle Rechte, auch künftige, ohne Zeilen in fe_role_areas/fe_role_actions, nicht änderbar) und
-- user (Benutzer); NULL = vom Super-Admin angelegte Rolle. Je Stadt höchstens eine Rolle je
-- system_key (UNIQUE, NULL mehrfach). Der Name ist je Stadt eindeutig (utf8mb4_unicode_ci: ohne
-- Groß- und Kleinschreibung). restrict_single_use wirkt erst ab Phase 2. filter_locations bzw.
-- filter_device_types = 1: Die Rolle sieht nur die zugeordneten Standorte bzw. Ausrüstungstypen;
-- die Rolle admin hat nie Filter oder Einschränkungen (CHECK). version steigt mit jeder Änderung
-- (gleichzeitiges Bearbeiten, 409 version_conflict). updated_by: Konto der letzten Änderung (in der
-- Regel ein Super-Admin), NULL = Migration oder Kommandozeile. (id, tenant_id) ist Ziel der
-- zusammengesetzten Fremdschlüssel aus fe_role_* und fe_users.
CREATE TABLE fe_roles (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED NOT NULL,
    system_key          VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NULL,
    name                VARCHAR(50)  NOT NULL,
    description         VARCHAR(200) NULL,
    restrict_single_use TINYINT(1)   NOT NULL DEFAULT 0,
    filter_locations    TINYINT(1)   NOT NULL DEFAULT 0,
    filter_device_types TINYINT(1)   NOT NULL DEFAULT 0,
    version             INT UNSIGNED NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by          INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_roles_id_tenant (id, tenant_id),
    UNIQUE KEY uq_fe_roles_tenant_name (tenant_id, name),
    UNIQUE KEY uq_fe_roles_tenant_system_key (tenant_id, system_key),
    KEY idx_fe_roles_updated_by (updated_by),
    CONSTRAINT fk_fe_roles_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_roles_updated_by FOREIGN KEY (updated_by) REFERENCES fe_users (id),
    CONSTRAINT chk_fe_roles_system_key CHECK (system_key IS NULL OR system_key IN ('admin', 'user')),
    CONSTRAINT chk_fe_roles_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
    CONSTRAINT chk_fe_roles_description
        CHECK (description IS NULL OR CHAR_LENGTH(TRIM(description)) > 0),
    CONSTRAINT chk_fe_roles_flags CHECK (restrict_single_use IN (0, 1)
        AND filter_locations IN (0, 1) AND filter_device_types IN (0, 1)),
    CONSTRAINT chk_fe_roles_admin CHECK (NOT (system_key <=> 'admin')
        OR (restrict_single_use = 0 AND filter_locations = 0 AND filter_device_types = 0)),
    CONSTRAINT chk_fe_roles_version CHECK (version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
