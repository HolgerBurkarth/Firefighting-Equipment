-- Test-Migration: Tabelle mit Startwerten.
CREATE TABLE fe_test_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Semikolon in Zeichenkette und Kommentar; darf nicht trennen */
INSERT INTO fe_test_items (name) VALUES ('Atemschutzmaske; Größe M'), ('Wärmebildkamera');
