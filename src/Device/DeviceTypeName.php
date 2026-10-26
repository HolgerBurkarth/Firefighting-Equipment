<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Name eines Gerätetyps, z. B. „Atemschutzmaske“ (Regeln: DeviceNameRules).
 */
final readonly class DeviceTypeName
{
    public const int MAX_LENGTH = 50;

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
