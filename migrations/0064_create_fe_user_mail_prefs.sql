-- Mail-Auswahl je Person (Phase 2, P2.10): Themen der Benachrichtigung „Log“, die eine Person bekommt. Eine Zeile =
-- angehakt. Ohne Zeilen gelten alle Themen (wer die Benachrichtigung schon vor P2.10 eingeschaltet hatte, bekommt
-- weiter alles). Konten ohne Stadt (Super-Admins) sind erlaubt, darum ohne tenant_id. Gelöscht werden Zeilen nur
-- von der Person selbst (Fremdschlüssel kaskadiert nie; Konten werden anonymisiert, nicht gelöscht).
CREATE TABLE fe_user_mail_prefs (
    user_id INT UNSIGNED NOT NULL,
    topic   VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (user_id, topic),
    CONSTRAINT fk_fe_user_mail_prefs_user FOREIGN KEY (user_id) REFERENCES fe_users (id),
    CONSTRAINT chk_fe_user_mail_prefs_topic CHECK (topic REGEXP '^[a-z_]{1,30}$' COLLATE utf8mb4_bin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
