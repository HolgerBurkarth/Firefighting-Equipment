<?php

declare(strict_types=1);

namespace FirefightingEquipment\Csv;

/**
 * Liest eine CSV-Datei für den Import (Gegenstück zu CsvFormat, aber toleranter, weil Dateien auch
 * aus Excel oder anderen Programmen kommen):
 *
 * - Kodierung: UTF-8 mit oder ohne BOM; ist die Datei kein gültiges UTF-8, gilt sie als
 *   Windows-1252 (Excel „CSV (Trennzeichen-getrennt)“) und wird umgewandelt. In Windows-1252 nicht
 *   belegte Bytes, NUL-Bytes und UTF-16 (Excel „Unicode-Text“) werden abgelehnt.
 * - Trennzeichen: Semikolon, Komma oder Tab, erkannt an der Kopfzeile (das Zeichen, bei dem die
 *   meisten Überschriften bekannt sind; bei Gleichstand in dieser Reihenfolge).
 * - Felder nach RFC 4180: in Anführungszeichen dürfen Trennzeichen, Zeilenumbrüche und verdoppelte
 *   Anführungszeichen stehen. Zeilenende CRLF, LF oder CR.
 *
 * Zeilennummern zählen Datensätze wie Excel (Kopfzeile = 1), auch leere; ein Feld mit Zeilenumbruch
 * verschiebt sie also nicht. Völlig leere Datensätze (auch „;;;“) werden übergangen.
 */
final class CsvReader
{
    public const string ENCODING_UTF8 = 'utf-8';
    public const string ENCODING_WINDOWS_1252 = 'windows-1252';

    /** Mögliche Trennzeichen in der Reihenfolge, die bei Gleichstand gilt. */
    public const array DELIMITERS = [';', ',', "\t"];

    /** In Windows-1252 nicht belegte Bytes (mbstring würde sie still zu Steuerzeichen machen). */
    private const string UNDEFINED_WINDOWS_1252 = '/[\x81\x8D\x8F\x90\x9D]/';

    private function __construct()
    {
    }

    /**
     * @param \Closure(string): bool $isKnownHeader ob eine Überschrift bekannt ist (Trennzeichen-Erkennung)
     *
     * @throws CsvException Datei leer, Kodierung nicht lesbar, Anführungszeichen falsch gesetzt
     */
    public static function read(string $bytes, \Closure $isKnownHeader): CsvTable
    {
        [$text, $encoding] = self::decode($bytes);
        $delimiter = self::detectDelimiter($text, $isKnownHeader);

        $header = null;
        $records = [];
        foreach (self::records($text, $delimiter) as $record) {
            if ($header === null) {
                $header = $record;
            } elseif (!$record->isEmpty()) {
                $records[] = $record;
            }
        }
        if ($header === null || $header->isEmpty()) {
            throw new CsvException('Die erste Zeile muss die Spaltenüberschriften enthalten.', 1);
        }

        return new CsvTable($encoding, $delimiter, $header, $records);
    }

    /**
     * @return array{string, string} Text in UTF-8 (ohne BOM) und erkannte Kodierung
     *
     * @throws CsvException
     */
    private static function decode(string $bytes): array
    {
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            throw new CsvException('Die Datei ist in UTF-16 gespeichert (z. B. Excel „Unicode-Text“). Bitte als „CSV UTF-8“ speichern.');
        }
        if (str_contains($bytes, "\0")) {
            throw new CsvException('Die Datei enthält Null-Bytes; sie ist keine CSV-Textdatei oder in UTF-16 gespeichert. Bitte als „CSV UTF-8“ speichern.');
        }

        $hasBom = str_starts_with($bytes, CsvFormat::BOM);
        if ($hasBom) {
            $bytes = substr($bytes, strlen(CsvFormat::BOM));
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            $text = $bytes;
            $encoding = self::ENCODING_UTF8;
        } elseif ($hasBom) {
            throw new CsvException('Die Datei beginnt mit der Kennung für UTF-8, enthält aber ungültige UTF-8-Zeichen.');
        } elseif (preg_match(self::UNDEFINED_WINDOWS_1252, $bytes) === 1) {
            throw new CsvException('Die Kodierung der Datei ist weder UTF-8 noch Windows-1252. Bitte als „CSV UTF-8“ speichern.');
        } else {
            $text = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
            $encoding = self::ENCODING_WINDOWS_1252;
        }
        if (trim($text) === '') {
            throw new CsvException('Die Datei ist leer.');
        }

        return [$text, $encoding];
    }

    /**
     * @param \Closure(string): bool $isKnownHeader
     */
    private static function detectDelimiter(string $text, \Closure $isKnownHeader): string
    {
        $best = self::DELIMITERS[0];
        $bestScore = -1;
        foreach (self::DELIMITERS as $delimiter) {
            try {
                $header = self::records($text, $delimiter)->current();
            } catch (CsvException) {
                continue;
            }
            if (!$header instanceof CsvRecord) {
                continue;
            }
            $score = count(array_filter($header->fields, static fn (string $f): bool => $isKnownHeader($f)));
            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Zerlegt den Text in Datensätze (auch leere).
     *
     * @return \Generator<int, CsvRecord>
     *
     * @throws CsvException Anführungszeichen nicht geschlossen oder Text direkt danach
     */
    private static function records(string $text, string $delimiter): \Generator
    {
        $length = strlen($text);
        $position = 0;
        $line = 1;
        $stops = $delimiter . "\r\n";

        while ($position < $length) {
            $fields = [];
            while (true) {
                if ($position < $length && $text[$position] === '"') {
                    $value = '';
                    $position++;
                    while (true) {
                        $quote = strpos($text, '"', $position);
                        if ($quote === false) {
                            throw new CsvException(sprintf('Zeile %d: Ein Anführungszeichen wird nicht geschlossen.', $line), $line);
                        }
                        $value .= substr($text, $position, $quote - $position);
                        $position = $quote + 1;
                        if ($position < $length && $text[$position] === '"') {
                            $value .= '"';
                            $position++;
                            continue;
                        }
                        break;
                    }
                    if ($position < $length && !str_contains($stops, $text[$position])) {
                        throw new CsvException(sprintf(
                            'Zeile %d: Nach einem schließenden Anführungszeichen muss ein Trennzeichen oder das Zeilenende folgen.',
                            $line,
                        ), $line);
                    }
                } else {
                    $span = strcspn($text, $stops, $position);
                    $value = substr($text, $position, $span);
                    $position += $span;
                }
                $fields[] = $value;

                if ($position >= $length) {
                    break;
                }
                $char = $text[$position];
                $position++;
                if ($char === $delimiter) {
                    continue;
                }
                if ($char === "\r" && $position < $length && $text[$position] === "\n") {
                    $position++;
                }
                break;
            }

            yield new CsvRecord($line, $fields);
            $line++;
        }
    }
}
