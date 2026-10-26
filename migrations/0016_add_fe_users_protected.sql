-- Geschützte Konten: Ein geschütztes Konto kann nicht gesperrt, gelöscht oder in der Rolle geändert
-- werden. Gesetzt und entfernt wird der Schutz nur per Kommandozeile (bin/user.php). Die Datenbank
-- erzwingt: geschützt nur bei einem aktiven, nicht gesperrten Super-Admin.
ALTER TABLE fe_users
    ADD COLUMN protected TINYINT(1) NOT NULL DEFAULT 0 AFTER locked_at,
    ADD CONSTRAINT chk_fe_users_protected CHECK (protected IN (0, 1)
        AND (protected = 0 OR (role = 'superadmin' AND status = 'active' AND locked_at IS NULL)));
