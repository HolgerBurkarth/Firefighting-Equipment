<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Scan-Code eines Geräts (Barcode, QR-Code, GS1-DataMatrix …), gespeichert wie eingegeben und exakt
 * verglichen (binär, Groß- und Kleinschreibung zählt). Dieselben Regeln prüft chk_fe_devices_scan_code.
 *
 * - Leerzeichen, Tab, CR und LF am Rand werden entfernt (Handscanner hängen oft CR/LF oder Tab an,
 *   Copy-Paste bringt Leerraum mit); danach leer = kein Scan-Code (null).
 * - Im Inhalt erlaubt sind alle Zeichen außer Steuerzeichen; ausgenommen sind die Trennzeichen EOT
 *   (0x04), GS (0x1D, FNC1 bei GS1) und RS (0x1E) aus ISO/IEC 15434. Tab, CR oder LF im Inhalt
 *   (meist zwei zusammengefügte Scans), unsichtbare Formatzeichen (\p{Cf}, z. B. Zero-Width-Space)
 *   sowie U+2028/2029 werden abgelehnt. Leerzeichen im Inhalt bleiben unverändert.
 * - Höchstens 200 Zeichen.
 *
 * Eindeutig je Stadt unter nicht ausgesonderten Geräten; das entscheidet der UNIQUE-Index auf
 * scan_code_key.
 */
final readonly class ScanCode
{
    public const int MAX_LENGTH = 200;

    /** Am Rand entfernte Zeichen */
    private const string EDGE = " \t\r\n";

    /** Erlaubte Trennzeichen und ihre sichtbare Form für Meldungen und Mails */
    public const array SEPARATORS = ["\x04" => '[EOT]', "\x1D" => '[GS]', "\x1E" => '[RS]'];

    private const string FORBIDDEN_CONTROL = '/[\x{00}-\x{03}\x{05}-\x{1C}\x{1F}\x{7F}-\x{9F}]/u';

    private const string FORBIDDEN_FORMAT = '/[\p{Cf}\x{2028}\x{2029}]/u';

    private function __construct(public string $value)
    {
    }

    /**
     * @return self|null null = kein Scan-Code (null oder nach dem Entfernen des Randes leer)
     *
     * @throws InvalidDeviceValueException
     */
    public static function fromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidDeviceValueException('Der Scan-Code ist kein gültiger UTF-8-Text.');
        }

        $value = trim($value, self::EDGE);
        if ($value === '') {
            return null;
        }

        $length = mb_strlen($value);
        if ($length > self::MAX_LENGTH) {
            throw new InvalidDeviceValueException(sprintf(
                'Der Scan-Code darf höchstens %d Zeichen lang sein (angegeben: %d).',
                self::MAX_LENGTH,
                $length,
            ));
        }

        if (preg_match(self::FORBIDDEN_CONTROL, $value) === 1) {
            throw new InvalidDeviceValueException(
                'Der Scan-Code darf außer den Trennzeichen GS, RS und EOT keine Steuerzeichen enthalten (z. B. Zeilenumbrüche oder Tabulatoren mitten im Code, oft zwei zusammengefügte Scans).',
            );
        }
        if (preg_match(self::FORBIDDEN_FORMAT, $value) === 1) {
            throw new InvalidDeviceValueException(
                'Der Scan-Code enthält unsichtbare Formatzeichen (z. B. ein Leerzeichen der Breite null). Bitte den Code erneut scannen oder eingeben.',
            );
        }

        return new self($value);
    }

    /**
     * Wert aus der Datenbank (dort per CHECK geprüft).
     */
    public static function fromDatabase(string $value): self
    {
        return new self($value);
    }

    /**
     * Lesbare Form für Meldungen und Mails: Trennzeichen als [GS], [RS], [EOT].
     */
    public static function display(string $value): string
    {
        return strtr($value, self::SEPARATORS);
    }
}
