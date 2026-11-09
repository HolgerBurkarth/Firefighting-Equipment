<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Stock;

use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Prüfung gegen den Sollbestand (Phase 2, P2.8): Würde eine Buchung eine Regel (fe_stock_rules) unterschreiten,
 * wird sie abgelehnt (409 rule_violation mit details.stock) bzw. mit der Aktion stock.override nach Rückfrage
 * gebucht. Gezählt wird wie StockRepository::overview (einsatzbereit, die Typen einer Regel zusammen, bei Ebene 1
 * mit den Fächern), aber IMMER über alle Ausrüstungen, nie nur die sichtbaren: Ein Datenfilter darf den Bestand
 * nicht verdecken.
 *
 * Abgelehnt wird nur, was den Bestand einer Regel VERRINGERT und danach unter der Soll-Anzahl liegt; Buchungen,
 * die ihn nicht verringern, sind immer erlaubt (auch bei einer schon unterschrittenen Regel). Aufrufen in der
 * Transaktion der Buchung, mit gesperrten Geräten.
 */
final readonly class StockGuard
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param list<array{device: Device, locationId: int, status: DeviceStatus}> $moves die geplanten Änderungen
     *
     * @return list<StockViolation> je unterschrittener Regel eine
     */
    public function violations(Tenant $tenant, array $moves): array
    {
        $locationIds = [];
        foreach ($moves as $move) {
            $locationIds[] = $move['device']->locationId;
            $locationIds[] = $move['locationId'];
        }
        $parents = $this->parents($tenant, array_values(array_unique($locationIds)));
        $scopeIds = [];
        foreach ($parents as $id => $parent) {
            $scopeIds[$id] = true;
            if ($parent !== null) {
                $scopeIds[$parent] = true;
            }
        }
        if ($scopeIds === []) {
            return [];
        }

        $violations = [];
        foreach ($this->rules($tenant, array_keys($scopeIds)) as $rule) {
            $delta = 0;
            foreach ($moves as $move) {
                $before = $this->counts($rule, $move['device']->deviceTypeId, $move['device']->locationId, $move['device']->status, $parents);
                $after = $this->counts($rule, $move['device']->deviceTypeId, $move['locationId'], $move['status'], $parents);
                $delta += ($after ? 1 : 0) - ($before ? 1 : 0);
            }
            if ($delta >= 0) {
                continue;
            }
            $left = $this->have($tenant, $rule) + $delta;
            if ($left < $rule['min_count']) {
                $violations[] = new StockViolation(
                    $rule['location_id'],
                    $rule['location_name'],
                    $rule['location_path'],
                    $rule['id'],
                    $rule['min_count'],
                    $rule['types'],
                    $left,
                );
            }
        }

        return $violations;
    }

    /**
     * @param array{location_id: int, types: list<array{id: int, name: string}>} $rule
     * @param array<int, int|null>                                                $parents
     */
    private function counts(array $rule, int $typeId, int $locationId, DeviceStatus $status, array $parents): bool
    {
        if ($status !== DeviceStatus::Ready || !in_array($typeId, array_column($rule['types'], 'id'), true)) {
            return false;
        }

        return $locationId === $rule['location_id'] || ($parents[$locationId] ?? null) === $rule['location_id'];
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, int|null> Standort => übergeordneter Standort
     */
    private function parents(Tenant $tenant, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT id, parent_id FROM fe_locations WHERE tenant_id = ? AND id IN (%s)',
            implode(', ', array_fill(0, count($ids), '?')),
        ));
        $stmt->execute([$tenant->id, ...$ids]);
        $parents = [];
        foreach ($stmt->fetchAll() as $row) {
            $parents[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }

        return $parents;
    }

    /**
     * @param list<int> $locationIds
     *
     * @return list<array{id: int, location_id: int, min_count: int, location_name: string, location_path: string, types: list<array{id: int, name: string}>}>
     */
    private function rules(Tenant $tenant, array $locationIds): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT r.id, r.location_id, r.min_count, l.name, p.name AS parent_name FROM fe_stock_rules r'
            . ' JOIN fe_locations l ON l.id = r.location_id AND l.tenant_id = r.tenant_id'
            . ' LEFT JOIN fe_locations p ON p.id = l.parent_id AND p.tenant_id = l.tenant_id'
            . ' WHERE r.tenant_id = ? AND r.location_id IN (%s) ORDER BY r.id',
            implode(', ', array_fill(0, count($locationIds), '?')),
        ));
        $stmt->execute([$tenant->id, ...$locationIds]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $types = $this->pdo->prepare(sprintf(
            'SELECT t.rule_id, y.id, y.name FROM fe_stock_rule_types t JOIN fe_device_types y ON y.id = t.device_type_id AND y.tenant_id = t.tenant_id'
            . ' WHERE t.tenant_id = ? AND t.rule_id IN (%s) ORDER BY NATURAL_SORT_KEY(y.name)',
            implode(', ', array_fill(0, count($rows), '?')),
        ));
        $types->execute([$tenant->id, ...array_map(static fn (array $row): int => (int) $row['id'], $rows)]);
        $byRule = [];
        foreach ($types->fetchAll() as $row) {
            $byRule[(int) $row['rule_id']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'location_id' => (int) $row['location_id'],
            'min_count' => (int) $row['min_count'],
            'location_name' => (string) $row['name'],
            'location_path' => $row['parent_name'] === null ? (string) $row['name'] : $row['parent_name'] . ' / ' . $row['name'],
            'types' => $byRule[(int) $row['id']] ?? [],
        ], $rows);
    }

    /**
     * @param array{id: int, location_id: int} $rule
     */
    private function have(Tenant $tenant, array $rule): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM fe_devices d WHERE d.tenant_id = ? AND d.status = 'ready'"
            . ' AND d.device_type_id IN (SELECT t.device_type_id FROM fe_stock_rule_types t WHERE t.rule_id = ?)'
            . ' AND (d.location_id = ? OR d.location_id IN (SELECT c.id FROM fe_locations c WHERE c.parent_id = ? AND c.tenant_id = ?))',
        );
        $stmt->execute([$tenant->id, $rule['id'], $rule['location_id'], $rule['location_id'], $tenant->id]);

        return (int) $stmt->fetchColumn();
    }
}
