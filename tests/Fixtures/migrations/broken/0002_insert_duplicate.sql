CREATE TABLE fe_test_broken (
    id INT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Doppelter Schlüssel: Diese Anweisung schlägt fehl.
INSERT INTO fe_test_broken (id) VALUES (1), (1);
