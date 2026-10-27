<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Booking;

use FirefightingEquipment\Device\InvalidDeviceValueException;

/**
 * Kennung einer Buchung vom Handy (client_ref): UUID Version 4 (z. B. aus crypto.randomUUID()),
 * gespeichert klein geschrieben. Eindeutig je Stadt (uq_fe_device_bookings_tenant_ref).
 */
final readonly class ClientRef
{
    /** Wie chk_fe_device_bookings_client_ref */
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private function __construct(public string $value)
    {
    }

    /**
     * Groß- und Kleinschreibung zählt nicht (Hex-Ziffern).
     *
     * @throws InvalidDeviceValueException
     */
    public static function fromString(string $value): self
    {
        $lower = strtolower($value);
        if (preg_match(self::PATTERN, $lower) !== 1) {
            throw new InvalidDeviceValueException('Das Feld "client_ref" muss eine UUID (Version 4) sein, z. B. 3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f.');
        }

        return new self($lower);
    }
}
