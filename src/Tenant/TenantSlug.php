<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

/**
 * Kürzel einer Stadt im URL-Pfad (/firefighting/<kürzel>/). Nach dem Anlegen unveränderlich.
 *
 * Erlaubt sind Kleinbuchstaben a-z, Ziffern und Bindestrich; der Bindestrich nicht am Anfang
 * oder Ende und nicht doppelt. Großbuchstaben werden abgelehnt, nicht umgewandelt.
 * Dieselben Regeln prüft die Datenbank (migrations/0001_create_fe_tenants.sql). Reihenfolge der
 * Prüfungen (für gleiche Meldungen im Frontend, siehe README "Stadt-Kürzel"): leer, Länge,
 * reserviert, Großbuchstaben (mit Vorschlag), Format.
 */
final readonly class TenantSlug
{
    public const int MAX_LENGTH = 50;
    public const string PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/D';

    /** Kürzel mit eigener Bedeutung, die nie an eine Stadt vergeben werden. */
    public const array RESERVED = ['service'];

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidTenantValueException
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new InvalidTenantValueException('Das Kürzel darf nicht leer sein.');
        }

        $length = mb_check_encoding($value, 'UTF-8') ? mb_strlen($value) : strlen($value);
        if ($length > self::MAX_LENGTH) {
            throw new InvalidTenantValueException(sprintf(
                'Das Kürzel darf höchstens %d Zeichen lang sein (angegeben: %d).',
                self::MAX_LENGTH,
                $length,
            ));
        }

        $lower = strtolower($value);
        if (in_array($lower, self::RESERVED, true)) {
            throw new InvalidTenantValueException(sprintf('Das Kürzel „%s“ ist reserviert.', $lower));
        }

        if ($lower !== $value && preg_match(self::PATTERN, $lower) === 1) {
            throw new InvalidTenantValueException(sprintf(
                'Das Kürzel darf keine Großbuchstaben enthalten. Gemeint ist vermutlich „%s“.',
                $lower,
            ), $lower);
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidTenantValueException(
                'Ungültiges Kürzel: erlaubt sind nur Kleinbuchstaben a-z, Ziffern und Bindestrich;'
                . ' der Bindestrich nicht am Anfang oder Ende und nicht doppelt.',
            );
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
