-- Scan-Code eines Geräts (Barcode, QR-Code, GS1-DataMatrix), optional. Gespeichert wie eingegeben
-- (nur Leerzeichen, Tab, CR und LF am Rand entfernt der Code) und exakt verglichen (utf8mb4_bin,
-- Groß- und Kleinschreibung zählt). Erlaubt sind alle Zeichen außer Steuerzeichen; ausgenommen sind
-- die Trennzeichen EOT (0x04), GS (0x1D) und RS (0x1E) aus GS1 und ISO/IEC 15434. Unsichtbare
-- Formatzeichen (\p{Cf}) und U+2028/2029 sind verboten, ebenso ein Leerzeichen am Rand (\\z statt $,
-- weil $ auch vor einem abschließenden Zeilenumbruch passt).
-- Eindeutig je Stadt nur unter nicht ausgesonderten Geräten: scan_code_key ist bei ausgesonderten
-- Geräten NULL (ein UNIQUE-Index erlaubt beliebig viele NULL), Muster wie identifier_key.
ALTER TABLE fe_devices
    ADD COLUMN scan_code VARCHAR(200) COLLATE utf8mb4_bin NULL AFTER identifier,
    ADD COLUMN scan_code_key VARCHAR(200) COLLATE utf8mb4_bin
        AS (IF(status = 'retired', NULL, scan_code)) STORED AFTER scan_code,
    ADD UNIQUE KEY uq_fe_devices_tenant_scan_code (tenant_id, scan_code_key),
    ADD CONSTRAINT chk_fe_devices_scan_code CHECK (scan_code IS NULL OR (scan_code <> ''
        AND scan_code NOT REGEXP '^ | \\z'
        AND scan_code NOT REGEXP '[\\x{00}-\\x{03}\\x{05}-\\x{1C}\\x{1F}\\x{7F}-\\x{9F}\\x{2028}\\x{2029}\\p{Cf}]'));
