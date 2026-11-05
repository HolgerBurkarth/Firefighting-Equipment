-- Standard-Rollen für alle bestehenden Städte (auch gesperrte): Admin (alle Rechte, auch künftige,
-- daher ohne Zeilen in fe_role_areas/fe_role_actions) und Benutzer (Rechte des heutigen Kontos
-- "user": Suchen und Termine ansehen, umbuchen, Status setzen). Neue Städte erhalten dieselben
-- Rollen beim Anlegen (TenantRepository::create über RoleRepository::createDefaults, Werte in
-- DefaultRoles); beide Stellen immer gemeinsam ändern. Nur INSERT, bestehende Daten bleiben.
INSERT INTO fe_roles (tenant_id, system_key, name, description, created_at, updated_at)
SELECT t.id, r.system_key, r.name, r.description, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM fe_tenants t
CROSS JOIN (
    SELECT 'admin' AS system_key, 'Admin' AS name, 'Alle Rechte, nicht änderbar' AS description
    UNION ALL SELECT 'user', 'Benutzer', 'Wie bisher; bekommt jede neue Stadt'
) r;

INSERT INTO fe_role_areas (role_id, tenant_id, area, level)
SELECT r.id, r.tenant_id, a.area, a.level
FROM fe_roles r
CROSS JOIN (
    SELECT 'search' AS area, 'read' AS level
    UNION ALL SELECT 'due', 'read'
) a
WHERE r.system_key = 'user';

INSERT INTO fe_role_actions (role_id, tenant_id, action)
SELECT r.id, r.tenant_id, a.action
FROM fe_roles r
CROSS JOIN (
    SELECT 'book.move' AS action
    UNION ALL SELECT 'book.status'
) a
WHERE r.system_key = 'user';
