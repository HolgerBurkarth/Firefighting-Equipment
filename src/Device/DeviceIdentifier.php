<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Kennung eines Geräts (z. B. „MA-01“), gespeichert wie eingegeben: Leerraum am Rand wird entfernt,
 * mehrere Leerzeichen werden zu einem zusammengefasst. Erlaubt sind Buchstaben (alle
 * Unicode-Buchstaben), Ziffern 0-9, Leerzeichen, Bindestrich, Schrägstrich und Punkt; höchstens
 * 30 Zeichen. Dieselben Regeln prüft chk_fe_devices_identifier.
 *
 * Zusammengesetzte Form: Ohne intl gibt es keine vollständige Unicode-Normalisierung. Zerlegte
 * Umlaute werden zusammengesetzt; andere kombinierende Zeichen werden abgelehnt.
 *
 * Eindeutig je Stadt unter nicht ausgesonderten Geräten ohne Groß- und Kleinschreibung (ß ≠ ss);
 * das entscheidet der UNIQUE-Index auf identifier_key.
 */
final readonly class DeviceIdentifier
{
    public const int MAX_LENGTH = 30;

    private const array DECOMPOSED_UMLAUTS = [
        "a\u{0308}" => 'ä', "o\u{0308}" => 'ö', "u\u{0308}" => 'ü',
        "A\u{0308}" => 'Ä', "O\u{0308}" => 'Ö', "U\u{0308}" => 'Ü',
    ];

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidDeviceValueException
     */
    public static function fromString(string $value): self
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidDeviceValueException('Die Kennung ist kein gültiger UTF-8-Text.');
        }

        $value = (string) preg_replace('/ {2,}/', ' ', trim(strtr($value, self::DECOMPOSED_UMLAUTS)));
        if ($value === '') {
            throw new InvalidDeviceValueException('Die Kennung darf nicht leer sein.');
        }

        $length = mb_strlen($value);
        if ($length > self::MAX_LENGTH) {
            throw new InvalidDeviceValueException(sprintf(
                'Die Kennung darf höchstens %d Zeichen lang sein (angegeben: %d).',
                self::MAX_LENGTH,
                $length,
            ));
        }

        if (preg_match('/\p{M}/u', $value) === 1) {
            throw new InvalidDeviceValueException(
                'Die Kennung enthält kombinierende Zeichen (z. B. einen einzeln eingegebenen Akzent). Bitte den Buchstaben direkt eingeben.',
            );
        }

        if (preg_match('~^[\p{L}0-9./-]+( [\p{L}0-9./-]+)*\z~u', $value) !== 1) {
            throw new InvalidDeviceValueException(
                'Die Kennung darf nur Buchstaben, Ziffern, Leerzeichen, Bindestrich (-), Schrägstrich (/) und Punkt (.) enthalten.',
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
}
