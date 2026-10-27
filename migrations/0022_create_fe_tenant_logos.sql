-- Logo einer Stadt (Erscheinungsbild), höchstens eines je Stadt. Gespeichert werden nur die vom
-- Server mit GD neu erzeugten PNG-Dateien (512, 192 und 180 px), nie die hochgeladene Datei.
-- version = die ersten 16 Hex-Zeichen von SHA-256 über die drei Dateien; sie steht als ?v=… in der
-- Adresse und im ETag. updated_by ist ein Konto derselben Stadt.
-- Das Logo ist keine Historie: Ersetzen überschreibt die Zeile, Entfernen löscht sie.
CREATE TABLE fe_tenant_logos (
    tenant_id  INT UNSIGNED NOT NULL,
    version    CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    png_512    MEDIUMBLOB   NOT NULL,
    png_192    MEDIUMBLOB   NOT NULL,
    png_180    MEDIUMBLOB   NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    updated_at DATETIME     NOT NULL,
    PRIMARY KEY (tenant_id),
    KEY idx_fe_tenant_logos_user (updated_by, tenant_id),
    CONSTRAINT fk_fe_tenant_logos_tenant FOREIGN KEY (tenant_id) REFERENCES fe_tenants (id),
    CONSTRAINT fk_fe_tenant_logos_user FOREIGN KEY (updated_by, tenant_id)
        REFERENCES fe_users (id, tenant_id),
    CONSTRAINT chk_fe_tenant_logos_version CHECK (version REGEXP '^[0-9a-f]{16}\\z'),
    CONSTRAINT chk_fe_tenant_logos_png CHECK (
        LEFT(png_512, 8) = X'89504E470D0A1A0A'
        AND LEFT(png_192, 8) = X'89504E470D0A1A0A'
        AND LEFT(png_180, 8) = X'89504E470D0A1A0A')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
