<?php

declare(strict_types=1);

namespace FirefightingEquipment\Csv;

/**
 * Format der CSV-Dateien des Exports (der Import liest es mit CsvReader): UTF-8 mit BOM (Excel
 * unter Windows erkennt sonst die Umlaute nicht), Semikolon als Trennzeichen, CRLF am Zeilenende, Felder nach RFC 4180 nur bei
 * Bedarf in Anführungszeichen (enthält das Feld ein Semikolon, Anführungszeichen, CR oder LF;
 * Anführungszeichen werden verdoppelt). Zeilenumbrüche innerhalb eines Felds (Notizen) bleiben LF.
 *
 * Schutz gegen Formel-Einschleusung: Werte, die mit =, +, -, @, Tab, CR oder ' beginnen, erhalten
 * ein vorangestelltes ' (Tabellenprogramme werten sie sonst als Formel aus). Der Import entfernt
 * daher immer genau ein führendes ' (removeFormulaGuard()); dank des ' in der Liste kommt jeder
 * Wert unverändert zurück.
 */
final class CsvFormat
{
    public const string BOM = "\xEF\xBB\xBF";
    public const string DELIMITER = ';';
    public const string EOL = "\r\n";

    /** Letzte Zeile eines abgebrochenen Exports (CsvResponse); der Import lehnt solche Dateien ab. */
    public const string ABORTED_LINE = 'Export abgebrochen – Datei unvollständig';

    /** Anfangszeichen, vor die ein ' gesetzt wird */
    public const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r", "'"];

    /**
     * Eine Zeile einschließlich CRLF.
     *
     * @param list<string|null> $fields null = leeres Feld
     */
    public static function line(array $fields): string
    {
        return implode(self::DELIMITER, array_map(self::field(...), $fields)) . self::EOL;
    }

    public static function field(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (in_array($value[0], self::FORMULA_PREFIXES, true)) {
            $value = "'" . $value;
        }
        if (strpbrk($value, self::DELIMITER . "\"\r\n") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * Gegenstück zum Formelschutz für den Import: entfernt genau ein führendes '.
     */
    public static function removeFormulaGuard(string $value): string
    {
        return str_starts_with($value, "'") ? substr($value, 1) : $value;
    }
}
