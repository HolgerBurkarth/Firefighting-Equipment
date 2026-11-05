-- Jedes Konto einer Stadt hat eine Rolle, Super-Admins (ohne Stadt) haben keine. Scheitert, wenn
-- Migration 0036 nicht alle Konten zugeordnet hat (dann ändert diese Migration nichts). Dass
-- fe_users.role = 'admin' genau zur Rolle Admin gehört, sichert der Code (UserRepository), weil
-- system_key in fe_roles steht und ein CHECK keine andere Tabelle lesen kann.
ALTER TABLE fe_users ADD CONSTRAINT chk_fe_users_role_id CHECK ((tenant_id IS NULL) = (role_id IS NULL));
