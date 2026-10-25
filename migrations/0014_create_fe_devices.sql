-- Geräte je Stadt. Nie gelöscht; ausgesondert = status 'retired'. Typ und Standort nur aus derselben
-- Stadt (zusammengesetzte Fremdschlüssel). status ist ascii_bin, damit IN (...) Groß- und
-- Kleinschreibung unterscheidet.
-- Kennung: gespeichert wie eingegeben; nur Buchstaben (alle Unicode-Buchstaben), Ziffern 0-9, Punkt,
-- Schrägstrich, Bindestrich und einzelne Leerzeichen dazwischen (\\z statt $, weil $ auch vor einem
-- abschließenden Zeilenumbruch passt). Eindeutig je Stadt nur unter nicht ausgesonderten Geräten,
-- ohne Groß- und Kleinschreibung, aber ohne ß/ss- oder é/e-Gleichsetzung: identifier_key ist die
-- kleingeschriebene Kennung in utf8mb4_bin, bei ausgesonderten Geräten NULL (ein UNIQUE-Index erlaubt
-- beliebig viele NULL). LOWER() kennt unter utf8mb4_unicode_ci das große ẞ nicht, daher REPLACE.
-- version: Versionszähler gegen gleichzeitige Änderungen (der Code erhöht ihn bei jeder Änderung).
CREATE TABLE fe_devices (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED  NOT NULL,
    identifier          VARCHAR(30)   NOT NULL,
    identifier_key      VARCHAR(30)   COLLATE utf8mb4_bin
        AS (IF(status = 'retired', NULL, REPLACE(LOWER(identifier), 'ẞ', 'ß'))) STORED,
    device_type_id      INT UNSIGNED  NOT NULL,
    location_id         INT UNSIGNED  NOT NULL,
    status              VARCHAR(12)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    serial_number       VARCHAR(100)  NULL,
    manufacturer        VARCHAR(100)  NULL,
    model               VARCHAR(100)  NULL,
    procured_on         DATE          NULL,
    next_inspection_due DATE          NULL,
    note                VARCHAR(1000) NULL,
    version             INT UNSIGNED  NOT NULL DEFAULT 1,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fe_devices_id_tenant (id, tenant_id),
    UNIQUE KEY uq_fe_devices_tenant_identifier (tenant_id, identifier_key),
    KEY idx_fe_devices_type (device_type_id, tenant_id),
    KEY idx_fe_devices_location (location_id, tenant_id),
    KEY idx_fe_devices_tenant_status (tenant_id, status),
    KEY idx_fe_devices_tenant_due (tenant_id, next_inspection_due),
    CONSTRAINT fk_fe_devices_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_devices_type FOREIGN KEY (device_type_id, tenant_id)
        REFERENCES fe_device_types (id, tenant_id),
    CONSTRAINT fk_fe_devices_location FOREIGN KEY (location_id, tenant_id)
        REFERENCES fe_locations (id, tenant_id),
    CONSTRAINT chk_fe_devices_identifier CHECK (identifier COLLATE utf8mb4_bin
        REGEXP '^[\\p{L}0-9./-]+( [\\p{L}0-9./-]+)*\\z'),
    CONSTRAINT chk_fe_devices_status
        CHECK (status IN ('ready', 'maintenance', 'defective', 'retired')),
    CONSTRAINT chk_fe_devices_serial_number
        CHECK (serial_number IS NULL OR CHAR_LENGTH(TRIM(serial_number)) > 0),
    CONSTRAINT chk_fe_devices_manufacturer
        CHECK (manufacturer IS NULL OR CHAR_LENGTH(TRIM(manufacturer)) > 0),
    CONSTRAINT chk_fe_devices_model CHECK (model IS NULL OR CHAR_LENGTH(TRIM(model)) > 0),
    CONSTRAINT chk_fe_devices_note CHECK (note IS NULL OR CHAR_LENGTH(TRIM(note)) > 0),
    CONSTRAINT chk_fe_devices_procured_on
        CHECK (procured_on IS NULL OR procured_on BETWEEN '1950-01-01' AND '2099-12-31'),
    CONSTRAINT chk_fe_devices_next_inspection_due CHECK (next_inspection_due IS NULL
        OR next_inspection_due BETWEEN '1950-01-01' AND '2099-12-31'),
    CONSTRAINT chk_fe_devices_version CHECK (version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
