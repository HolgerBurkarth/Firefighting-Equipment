<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

/**
 * Name oder Anzeigename einer Person, z. B. „Erika Musterfrau“. Leerraum am Rand wird entfernt.
 */
final readonly class PersonName
{
    public const int MAX_LENGTH = 100;

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidUserValueException
     */
    public static function fromString(string $value): self
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidUserValueException('Der Name ist kein gültiger UTF-8-Text.');
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidUserValueException('Der Name darf nicht leer sein.');
        }

        $length = mb_strlen($value);
        if ($length > self::MAX_LENGTH) {
            throw new InvalidUserValueException(sprintf(
                'Der Name darf höchstens %d Zeichen lang sein (angegeben: %d).',
                self::MAX_LENGTH,
                $length,
            ));
        }

        if (preg_match('/\p{Cc}/u', $value) === 1) {
            throw new InvalidUserValueException('Der Name darf keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.');
        }

        return new self($value);
    }
}
