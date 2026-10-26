<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Gerätetyp einer Stadt. Deaktivierte Typen (active = false) sind kein Ziel für neue Geräte;
 * bestehende Geräte behalten ihren Typ.
 */
final readonly class DeviceType
{
    public function __construct(
        public int $id,
        public DeviceTypeName $name,
        public bool $active,
        /** nicht ausgesonderte Geräte dieses Typs */
        public int $deviceCount,
        /** UTC */
        public \DateTimeImmutable $createdAt,
        /** UTC */
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
