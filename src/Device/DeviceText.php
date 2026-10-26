<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Regeln für freie Texte an Geräten: Seriennummer, Hersteller, Modell (je höchstens 100 Zeichen),
 * Notiz (höchstens 1000), Kommentar zu einem Ereignis (höchstens 500) und Suchtext der Liste
 * (höchstens 100). Leerraum am Rand wird entfernt, ein leerer Text wird null (gespeichert als NULL).
 * Steuerzeichen sind verboten, nur die Notiz darf Zeilenumbrüche enthalten (CRLF und CR werden LF).
 */
final class DeviceText
{
    public const int MAX_LENGTH = 100;
    public const int NOTE_MAX_LENGTH = 1000;
    public const int COMMENT_MAX_LENGTH = 500;
    public const int SEARCH_MAX_LENGTH = 100;

    private function __construct()
    {
    }

    /**
     * Seriennummer, Hersteller, Modell oder Notiz.
     *
     * @throws InvalidDeviceValueException
     */
    public static function field(?string $value, DeviceField $field): ?string
    {
        return match ($field) {
            DeviceField::SerialNumber, DeviceField::Manufacturer, DeviceField::Model => self::check($value, $field->subject(), self::MAX_LENGTH, false),
            DeviceField::Note => self::check($value, $field->subject(), self::NOTE_MAX_LENGTH, true),
            default => throw new \LogicException(sprintf('Kein Textfeld: %s', $field->value)),
        };
    }

    /**
     * @throws InvalidDeviceValueException
     */
    public static function comment(?string $value): ?string
    {
        return self::check($value, 'Der Kommentar', self::COMMENT_MAX_LENGTH, false);
    }

    /**
     * @throws InvalidDeviceValueException
     */
    public static function search(?string $value): ?string
    {
        return self::check($value, 'Der Suchtext', self::SEARCH_MAX_LENGTH, false);
    }

    /**
     * @throws InvalidDeviceValueException
     */
    private static function check(?string $value, string $subject, int $maxLength, bool $multiline): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidDeviceValueException(sprintf('%s ist kein gültiger UTF-8-Text.', $subject));
        }

        if ($multiline) {
            $value = str_replace(["\r\n", "\r"], "\n", $value);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $length = mb_strlen($value);
        if ($length > $maxLength) {
            throw new InvalidDeviceValueException(sprintf(
                '%s darf höchstens %d Zeichen lang sein (angegeben: %d).',
                $subject,
                $maxLength,
                $length,
            ));
        }

        if (preg_match($multiline ? '/[^\P{Cc}\n]/u' : '/\p{Cc}/u', $value) === 1) {
            throw new InvalidDeviceValueException($multiline
                ? sprintf('%s darf außer Zeilenumbrüchen keine Steuerzeichen (z. B. Tabulatoren) enthalten.', $subject)
                : sprintf('%s darf keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.', $subject));
        }

        return $value;
    }
}
