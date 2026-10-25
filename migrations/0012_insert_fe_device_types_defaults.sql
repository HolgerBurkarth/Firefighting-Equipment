-- Start-Gerätetypen für alle bestehenden Städte (auch gesperrte). Neue Städte erhalten dieselben
-- Typen beim Anlegen (TenantRepository::create, Liste in DefaultDeviceTypes::NAMES).
INSERT INTO fe_device_types (tenant_id, name, active, created_at, updated_at)
SELECT t.id, d.name, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM fe_tenants t
CROSS JOIN (
    SELECT 'Atemschutzmaske' AS name
    UNION ALL SELECT 'Atemschutzgerät'
    UNION ALL SELECT 'Funkgerät'
    UNION ALL SELECT 'Wärmebildkamera'
) d;
