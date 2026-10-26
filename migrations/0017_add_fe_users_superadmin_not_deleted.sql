-- Super-Admins werden nie gelöscht (anonymisiert), sondern nur gesperrt.
ALTER TABLE fe_users ADD CONSTRAINT chk_fe_users_superadmin_not_deleted
    CHECK (role <> 'superadmin' OR status <> 'deleted');
