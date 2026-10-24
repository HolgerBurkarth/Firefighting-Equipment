<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

/**
 * E-Mail-Adresse eines Kontos, getrimmt und kleingeschrieben. Verglichen wird binär
 * („strasse@…“ und „straße@…“ sind verschiedene Adressen).
 *
 * Mindestens so streng wie chk_fe_users_email (migrations/0002_create_fe_users.sql): genau ein @,
 * beide Seiten nicht leer, kein Leerraum, keine Steuerzeichen. Zusätzlich muss die Domain einen
 * Punkt enthalten (nicht am Anfang oder Ende, nicht doppelt).
 */
final readonly class EmailAddress
{
    public const int MAX_LENGTH = 254;

    private const string PATTERN = '/^[^@\s\p{Z}\p{C}]+@([^@\s\p{Z}\p{C}]+)$/uD';
    private const string DOMAIN_PATTERN = '/^[^.]+(\.[^.]+)+$/uD';

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidUserValueException
     */
    public static function fromString(string $value): self
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidUserValueException('Die E-Mail-Adresse ist kein gültiger UTF-8-Text.');
        }

        $value = mb_strtolower(trim($value));
        if ($value === '') {
            throw new InvalidUserValueException('Die E-Mail-Adresse darf nicht leer sein.');
        }

        $length = mb_strlen($value);
        if ($length > self::MAX_LENGTH) {
            throw new InvalidUserValueException(sprintf(
                'Die E-Mail-Adresse darf höchstens %d Zeichen lang sein (angegeben: %d).',
                self::MAX_LENGTH,
                $length,
            ));
        }

        if (preg_match(self::PATTERN, $value, $match) !== 1 || preg_match(self::DOMAIN_PATTERN, $match[1]) !== 1) {
            throw new InvalidUserValueException('Ungültige E-Mail-Adresse: erwartet wird die Form name@beispiel.de.');
        }

        return new self($value);
    }

    /**
     * Teil vor dem @.
     */
    public function localPart(): string
    {
        return substr($this->value, 0, (int) strpos($this->value, '@'));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
