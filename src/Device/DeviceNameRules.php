<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Gemeinsame Regeln für Namen von Gerätetypen und Standorten (wie TenantName): Leerraum am Rand
 * wird entfernt, nicht leer, höchstens $maxLength Zeichen, keine Steuerzeichen.
 */
final class DeviceNameRules
{
    private function __construct()
    {
    }

    /**
     * @return string der getrimmte Name
     *
     * @throws InvalidDeviceValueException
     */
    public static function check(string $value, int $maxLength): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidDeviceValueException('Der Name ist kein gültiger UTF-8-Text.');
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidDeviceValueException('Der Name darf nicht leer sein.');
        }

        $length = mb_strlen($value);
        if ($length > $maxLength) {
            throw new InvalidDeviceValueException(sprintf(
                'Der Name darf höchstens %d Zeichen lang sein (angegeben: %d).',
                $maxLength,
                $length,
            ));
        }

        if (preg_match('/\p{Cc}/u', $value) === 1) {
            throw new InvalidDeviceValueException('Der Name darf keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.');
        }

        return $value;
    }
}
