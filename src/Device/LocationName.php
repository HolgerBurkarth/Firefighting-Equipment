<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Name eines Standorts, z. B. „HLF 20“ oder „Geräteraum“ (Regeln: DeviceNameRules).
 */
final readonly class LocationName
{
    public const int MAX_LENGTH = 100;

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidDeviceValueException
     */
    public static function fromString(string $value): self
    {
        return new self(DeviceNameRules::check($value, self::MAX_LENGTH));
    }
}
