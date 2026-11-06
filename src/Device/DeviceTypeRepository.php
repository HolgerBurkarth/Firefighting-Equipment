<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Permission\DeviceVisibility;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Zugriff auf fe_device_types, immer gefiltert nach Stadt. Gerätetypen werden nie gelöscht, nur
 * deaktiviert; es gibt bewusst keine Methode zum Löschen. Ob ein Name schon vergeben ist,
 * entscheidet der UNIQUE-Index (utf8mb4_unicode_ci: ohne Groß- und Kleinschreibung, ß = ss), so
 * auch bei gleichzeitigen Aufrufen.
 *
 * Datenfilter (DeviceVisibility, Pflicht im Konstruktor): mit Typfilter nur sichtbare Typen;
 * Gerätezahlen nur sichtbarer Geräte. Ändern erreichen nur Rollen ohne Datenfilter (F3).
 */
final readonly class DeviceTypeRepository
{
    private const int ER_DUP_ENTRY = 1062;

    public function __construct(private \PDO $pdo, private DeviceVisibility $visibility)
    {
    }

    /**
     * @param bool $includeInactive auch deaktivierte Typen
     *
     * @return list<DeviceType> nach Name
     */
    public function listByTenant(Tenant $tenant, bool $includeInactive): array
    {
        return $this->fetch($tenant, $includeInactive ? '' : ' AND t.active = 1', []);
    }

    public function find(Tenant $tenant, int $id): ?DeviceType
    {
        return $this->fetch($tenant, ' AND t.id = ?', [$id])[0] ?? null;
    }

    /**
     * @throws DuplicateDeviceNameException
     */
    public function create(Tenant $tenant, DeviceTypeName $name): DeviceType
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO fe_device_types (tenant_id, name, active, created_at, updated_at)'
                . ' VALUES (?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            )->execute([$tenant->id, $name->value]);
        } catch (\PDOException $e) {
            throw self::isDuplicate($e) ? DuplicateDeviceNameException::deviceType($name) : $e;
        }

        return $this->find($tenant, (int) $this->pdo->lastInsertId())
            ?? throw new \LogicException('Angelegter Ausrüstungstyp nicht gefunden.');
    }

    /**
     * Groß- und Kleinschreibung zählt als Änderung (z. B. „funkgerät“ → „Funkgerät“).
     *
     * @throws DeviceTypeNotFoundException
     * @throws DuplicateDeviceNameException
     */
    public function rename(Tenant $tenant, int $id, DeviceTypeName $name): DeviceType
    {
        try {
            $this->pdo->prepare(
                'UPDATE fe_device_types SET name = ?, updated_at = UTC_TIMESTAMP()'
                . ' WHERE id = ? AND tenant_id = ? AND name COLLATE utf8mb4_bin <> ?',
            )->execute([$name->value, $id, $tenant->id, $name->value]);
        } catch (\PDOException $e) {
            throw self::isDuplicate($e) ? DuplicateDeviceNameException::deviceType($name) : $e;
        }

        return $this->find($tenant, $id) ?? throw DeviceTypeNotFoundException::forId($id);
    }

    /**
     * Aktiviert (true) oder deaktiviert (false) einen Typ; wiederholbar. Bestehende Geräte behalten
     * ihren Typ.
     *
     * @throws DeviceTypeNotFoundException
     */
    public function setActive(Tenant $tenant, int $id, bool $active): DeviceType
    {
        $this->pdo->prepare(
            'UPDATE fe_device_types SET active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ? AND active <> ?',
        )->execute([(int) $active, $id, $tenant->id, (int) $active]);

        return $this->find($tenant, $id) ?? throw DeviceTypeNotFoundException::forId($id);
    }

    /**
     * @param string    $where SQL aus diesem Code, nie aus Eingaben
     * @param list<int> $params
     *
     * @return list<DeviceType>
     */
    private function fetch(Tenant $tenant, string $where, array $params): array
    {
        [$visible, $visibleParams] = $this->visibility->sql('d');
        [$types, $typeParams] = $this->visibility->deviceTypeSql('t.id');
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.name, t.active, t.created_at, t.updated_at, COALESCE(c.devices, 0) AS devices'
            . ' FROM fe_device_types t LEFT JOIN ('
            . "SELECT d.device_type_id, COUNT(*) AS devices FROM fe_devices d WHERE d.tenant_id = ? AND d.status <> 'retired'" . $visible
            . ' GROUP BY d.device_type_id'
            . ') c ON c.device_type_id = t.id'
            . ' WHERE t.tenant_id = ?' . $types . $where
            . ' ORDER BY t.name, t.id',
        );
        $stmt->execute([$tenant->id, ...$visibleParams, $tenant->id, ...$typeParams, ...$params]);

        return array_map(
            static fn (array $row): DeviceType => new DeviceType(
                (int) $row['id'],
                DeviceTypeName::fromString((string) $row['name']),
                (int) $row['active'] === 1,
                (int) $row['devices'],
                self::utc((string) $row['created_at']),
                self::utc((string) $row['updated_at']),
            ),
            $stmt->fetchAll(),
        );
    }

    private static function isDuplicate(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === self::ER_DUP_ENTRY;
    }

    private static function utc(string $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'))
            ?: throw new \UnexpectedValueException(sprintf('Ungültiger Zeitstempel: %s', $value));
    }
}
