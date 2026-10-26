<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Datum eines Geräts (Beschaffung, nächste Prüfung fällig) im Format JJJJ-MM-TT, zwischen
 * 1950-01-01 und 2099-12-31 wie die CHECK-Regeln des Schemas (fängt Tippfehler wie „0202“ ab).
 */
final readonly class DeviceDate
{
    public const string MIN = '1950-01-01';
    public const string MAX = '2099-12-31';

    private function __construct(public string $value)
    {
    }

    /**
     * @param string $subject Bezeichnung für die Meldung, z. B. „Das Beschaffungsdatum“
     *
     * @throws InvalidDeviceValueException
     */
    public static function fromString(string $value, string $subject): self
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidDeviceValueException(sprintf('%s muss ein gültiges Datum im Format JJJJ-MM-TT sein.', $subject));
        }
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidDeviceValueException(sprintf('%s muss zwischen %s und %s liegen.', $subject, self::MIN, self::MAX));
        }

        return new self($value);
    }

    /**
     * Wert aus der Datenbank (Spalte DATE, dort per CHECK geprüft).
     */
    public static function fromDatabase(string $value): self
    {
        return new self($value);
    }
}
