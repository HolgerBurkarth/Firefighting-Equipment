-- Aufgabe eines Standorts (Phase 2, Schritt P2.2): task NULL = ohne Aufgabe (alles wie bisher), nur
-- Ebene 1. is_default_store: das Standard-Lager (höchstens eines je Stadt, über default_store_key
-- UNIQUE; nur bei task = store). collection_accepts: was die Sammelstelle annimmt (genau bei
-- task = collection). Spalten mit Standardwerten, bestehende Standorte bleiben ohne Aufgabe.
ALTER TABLE fe_locations
    ADD COLUMN task VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER kind,
    ADD COLUMN is_default_store TINYINT(1) NOT NULL DEFAULT 0 AFTER task,
    ADD COLUMN collection_accepts VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER is_default_store,
    ADD COLUMN default_store_key INT UNSIGNED AS (IF(is_default_store = 1, tenant_id, NULL)) STORED AFTER collection_accepts,
    ADD UNIQUE KEY uq_fe_locations_default_store (default_store_key),
    ADD CONSTRAINT chk_fe_locations_task CHECK (task IS NULL
        OR (level = 1 AND task IN ('store', 'collection', 'workshop', 'returns', 'borrower'))),
    ADD CONSTRAINT chk_fe_locations_default_store CHECK (is_default_store IN (0, 1)
        AND (is_default_store = 0 OR task <=> 'store')),
    ADD CONSTRAINT chk_fe_locations_collection_accepts CHECK (
        (task <=> 'collection') = (collection_accepts IS NOT NULL)
        AND (collection_accepts IS NULL OR collection_accepts IN ('used', 'defective', 'both')));
