<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Rolle einer Stadt mit Bereichen, Aktionen und Datenfiltern. Schlüssel der Bereiche und Aktionen
 * sind die Werte aus fe_role_areas bzw. fe_role_actions (Katalog folgt mit Schritt 1.3).
 */
final readonly class Role
{
    /**
     * @param array<string, 'read'|'write'> $areas         Bereich → Stufe, nach Bereich sortiert
     * @param list<string>                   $actions       sortiert
     * @param list<int>                      $locationIds   Datenfilter (nur mit $filterLocations), aufsteigend
     * @param list<int>                      $deviceTypeIds Datenfilter (nur mit $filterDeviceTypes), aufsteigend
     */
    public function __construct(
        public int $id,
        public int $tenantId,
        public ?SystemRole $systemRole,
        public string $name,
        public ?string $description,
        public bool $restrictSingleUse,
        public bool $filterLocations,
        public bool $filterDeviceTypes,
        public array $areas,
        public array $actions,
        public array $locationIds,
        public array $deviceTypeIds,
        public int $version,
        /** UTC */
        public \DateTimeImmutable $createdAt,
        /** UTC */
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
