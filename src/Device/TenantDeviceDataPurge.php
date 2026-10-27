<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Löscht Geräte, Historie, Standorte, Quittungen der Buchungen und Hinweise (samt Verlauf) einer
 * Stadt (bin/tenant.php purge-device-data).
 *
 * Bewusste Ausnahme von "Geräte werden nie gelöscht" und "Historie nur INSERT": nur für Städte ohne
 * echte Daten (z. B. Testdaten vor der Erstbefüllung). Deshalb eine eigene Klasse statt einer Methode
 * in einem Repository. Gerätetypen, Benutzer, Einladungscodes, Sitzungen und die Stadt bleiben.
 */
final readonly class TenantDeviceDataPurge
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function count(Tenant $tenant): DeviceDataCounts
    {
        $stmt = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM fe_devices WHERE tenant_id = :d),'
            . ' (SELECT COUNT(*) FROM fe_device_events WHERE tenant_id = :e),'
            . ' (SELECT COUNT(*) FROM fe_locations WHERE tenant_id = :l),'
            . ' (SELECT COUNT(*) FROM fe_device_bookings WHERE tenant_id = :b),'
            . ' (SELECT COUNT(*) FROM fe_device_notices WHERE tenant_id = :n)',
        );
        $stmt->execute(['d' => $tenant->id, 'e' => $tenant->id, 'l' => $tenant->id, 'b' => $tenant->id, 'n' => $tenant->id]);
        /** @var array{0: int|string, 1: int|string, 2: int|string, 3: int|string, 4: int|string} $row */
        $row = $stmt->fetch(\PDO::FETCH_NUM);

        return new DeviceDataCounts((int) $row[0], (int) $row[1], (int) $row[2], (int) $row[3], (int) $row[4]);
    }

    /**
     * Löscht in EINER eigenen Transaktion (eine bereits offene ist ein Fehler): Verlauf der Hinweise,
     * Hinweise, Quittungen, Ereignisse, Geräte, Unterstandorte, Standorte der Ebene 1 (Reihenfolge der
     * Fremdschlüssel). Die Standorte werden
     * zuerst gesperrt, damit niemand währenddessen ein Gerät dorthin legt; scheitert etwas, wird
     * alles zurückgenommen.
     *
     * @return DeviceDataCounts gelöschte Zeilen
     */
    public function purge(Tenant $tenant): DeviceDataCounts
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('TenantDeviceDataPurge startet eine eigene Transaktion; keine offene Transaktion erlaubt.');
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM fe_locations WHERE tenant_id = ? ORDER BY id FOR UPDATE');
            $lock->execute([$tenant->id]);
            $lock->fetchAll();

            $this->delete('DELETE FROM fe_device_notice_actions WHERE tenant_id = ?', $tenant);
            $notices = $this->delete('DELETE FROM fe_device_notices WHERE tenant_id = ?', $tenant);
            $bookings = $this->delete('DELETE FROM fe_device_bookings WHERE tenant_id = ?', $tenant);
            $events = $this->delete('DELETE FROM fe_device_events WHERE tenant_id = ?', $tenant);
            $devices = $this->delete('DELETE FROM fe_devices WHERE tenant_id = ?', $tenant);
            $locations = $this->delete('DELETE FROM fe_locations WHERE tenant_id = ? AND level = 2', $tenant)
                + $this->delete('DELETE FROM fe_locations WHERE tenant_id = ? AND level = 1', $tenant);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return new DeviceDataCounts($devices, $events, $locations, $bookings, $notices);
    }

    private function delete(string $sql, Tenant $tenant): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tenant->id]);

        return $stmt->rowCount();
    }
}
