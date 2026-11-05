<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

use FirefightingEquipment\Device\Device;

/**
 * Datenfilter „Sieht nur …“ einer Rolle (Plan Phase 1, Abschnitt 4): welche Geräte, Standorte und
 * Gerätetypen ein Konto sieht. Ein Gerät ist sichtbar, wenn sein Typ UND sein Standort sichtbar sind.
 * Je Art null = kein Filter (alle), sonst die erlaubten IDs; die Standorte enthalten die Fächer der
 * gewählten Standorte der Ebene 1 schon (PermissionRepository). Ein eingeschalteter Filter ohne
 * Auswahl (leere Liste) zeigt nichts.
 *
 * Die Repositorys verlangen sie im Konstruktor (ohne Standardwert); ausdrücklich alle
 * (DeviceVisibility::all()) gilt für die Rolle Admin, Kommandozeile, Super-Admins und Abläufe, die
 * nur Rollen ohne Datenfilter erreichen (F3, z. B. CSV-Import).
 */
final readonly class DeviceVisibility
{
    /** Name eines Standorts außerhalb des Filters in Verlauf, Hinweisen, Mails und CSV. */
    public const string OTHER_LOCATION = 'anderer Standort';

    /**
     * @param list<int>|null $locationIds   null = alle Standorte
     * @param list<int>|null $deviceTypeIds null = alle Gerätetypen
     */
    private function __construct(
        private ?array $locationIds,
        private ?array $deviceTypeIds,
    ) {
    }

    public static function all(): self
    {
        return new self(null, null);
    }

    /** Sieht nichts (Konto ohne Rolle, Permissions::none()). */
    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * @param list<int>|null $locationIds   null = kein Standortfilter; Fächer schon enthalten
     * @param list<int>|null $deviceTypeIds null = kein Typfilter
     */
    public static function restricted(?array $locationIds, ?array $deviceTypeIds): self
    {
        $normalize = static function (?array $ids): ?array {
            if ($ids === null) {
                return null;
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids);

            return $ids;
        };

        return new self($normalize($locationIds), $normalize($deviceTypeIds));
    }

    public function isAll(): bool
    {
        return $this->locationIds === null && $this->deviceTypeIds === null;
    }

    public function filtersLocations(): bool
    {
        return $this->locationIds !== null;
    }

    public function filtersDeviceTypes(): bool
    {
        return $this->deviceTypeIds !== null;
    }

    public function allowsLocation(int $id): bool
    {
        return $this->locationIds === null || in_array($id, $this->locationIds, true);
    }

    public function allowsDeviceType(int $id): bool
    {
        return $this->deviceTypeIds === null || in_array($id, $this->deviceTypeIds, true);
    }

    public function allows(int $deviceTypeId, int $locationId): bool
    {
        return $this->allowsDeviceType($deviceTypeId) && $this->allowsLocation($locationId);
    }

    public function allowsDevice(Device $device): bool
    {
        return $this->allows($device->deviceTypeId, $device->locationId);
    }

    /**
     * Bedingung auf die Spalten eines Geräts (z. B. d.device_type_id, d.location_id), mit führendem
     * AND; ohne Filter leer. Leere Auswahl: nie wahr.
     *
     * @param string $device Alias von fe_devices (nur [a-z_])
     *
     * @return array{string, list<int>} SQL aus diesem Code und Parameter
     */
    public function sql(string $device): array
    {
        if (preg_match('/\A[a-z_]+\z/', $device) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Alias.');
        }
        [$types, $typeParams] = self::in($device . '.device_type_id', $this->deviceTypeIds);
        [$locations, $locationParams] = self::in($device . '.location_id', $this->locationIds);

        return [$types . $locations, [...$typeParams, ...$locationParams]];
    }

    /**
     * Bedingung nur auf eine Spalte mit einer Standort-ID (z. B. Standorte der Liste), wie sql().
     *
     * @param string $column z. B. l.id (nur [a-z_.])
     *
     * @return array{string, list<int>}
     */
    public function locationSql(string $column): array
    {
        if (preg_match('/\A[a-z_]+\.[a-z_]+\z/', $column) !== 1) {
            throw new \InvalidArgumentException('Ungültige Spalte.');
        }

        return self::in($column, $this->locationIds);
    }

    /**
     * Bedingung nur auf eine Spalte mit einer Gerätetyp-ID, wie sql().
     *
     * @param string $column z. B. t.id (nur [a-z_.])
     *
     * @return array{string, list<int>}
     */
    public function deviceTypeSql(string $column): array
    {
        if (preg_match('/\A[a-z_]+\.[a-z_]+\z/', $column) !== 1) {
            throw new \InvalidArgumentException('Ungültige Spalte.');
        }

        return self::in($column, $this->deviceTypeIds);
    }

    /**
     * Kennung für permissions.version in auth/me.
     *
     * @return array{locations: list<int>|null, device_types: list<int>|null}
     */
    public function toArray(): array
    {
        return ['locations' => $this->locationIds, 'device_types' => $this->deviceTypeIds];
    }

    /**
     * @param list<int>|null $ids
     *
     * @return array{string, list<int>}
     */
    private static function in(string $column, ?array $ids): array
    {
        if ($ids === null) {
            return ['', []];
        }
        if ($ids === []) {
            return [' AND 0 = 1', []];
        }

        return [' AND ' . $column . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids];
    }
}
