<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Stock;

use FirefightingEquipment\Device\DeviceRuleViolationException;
use FirefightingEquipment\Device\InvalidDeviceValueException;
use FirefightingEquipment\Device\LocationNotFoundException;
use FirefightingEquipment\Permission\DeviceVisibility;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Sollbestand (Phase 2, P2.7; fe_stock_rules, fe_stock_rule_types): je Standort Regeln „mindestens N
 * einsatzbereite Ausrüstungen dieser Typen“ (die Typen einer Regel zählen zusammen). Bei der Ebene 1 zählen
 * die Fächer mit. „Außer Betrieb“ wird berechnet: Eine Regel ist unterschritten, wenn weniger einsatzbereite
 * (Status `ready`, nicht ausgesondert) Ausrüstungen dieser Typen dort stehen. Mit Datenfilter nur sichtbare
 * Standorte und Ausrüstung.
 */
final readonly class StockRepository
{
    /** Höchstzahl der Regeln je Standort und der Typen je Regel. */
    public const int MAX_RULES = 20;
    public const int MAX_TYPES = 20;
    public const int MAX_COUNT = 99;

    public function __construct(private \PDO $pdo, private DeviceVisibility $visibility)
    {
    }

    /**
     * Die Regeln der Stadt mit dem Ist-Bestand, nach Standort (Ebene 1 vor ihren Fächern).
     *
     * @return list<array{location: array{id: int, name: string, path: string, level: int}, rules: list<array{id: int, min_count: int, types: list<array{id: int, name: string}>, have: int, missing: int}>, out_of_service: bool}>
     */
    public function overview(Tenant $tenant, ?int $onlyLocationId = null): array
    {
        [$deviceFilter, $deviceParams] = $this->visibility->sql('d');
        [$locationFilter, $locationParams] = $this->visibility->locationSql('r.location_id');
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.location_id, r.min_count, l.name, l.parent_id, p.name AS parent_name,'
            . " (SELECT COUNT(*) FROM fe_devices d WHERE d.tenant_id = r.tenant_id AND d.status = 'ready'"
            . ' AND d.device_type_id IN (SELECT t.device_type_id FROM fe_stock_rule_types t WHERE t.rule_id = r.id)'
            . ' AND (d.location_id = r.location_id OR d.location_id IN (SELECT c.id FROM fe_locations c WHERE c.parent_id = r.location_id AND c.tenant_id = r.tenant_id))'
            . $deviceFilter . ') AS have'
            . ' FROM fe_stock_rules r JOIN fe_locations l ON l.id = r.location_id AND l.tenant_id = r.tenant_id'
            . ' LEFT JOIN fe_locations p ON p.id = l.parent_id AND p.tenant_id = l.tenant_id'
            . ' WHERE r.tenant_id = ? AND l.active = 1' . $locationFilter . ($onlyLocationId === null ? '' : ' AND r.location_id = ?')
            . ' ORDER BY NATURAL_SORT_KEY(COALESCE(p.name, l.name)), l.parent_id IS NOT NULL, NATURAL_SORT_KEY(l.name), r.id',
        );
        $stmt->execute([...$deviceParams, $tenant->id, ...$locationParams, ...($onlyLocationId === null ? [] : [$onlyLocationId])]);
        $rows = $stmt->fetchAll();

        $types = $this->pdo->prepare(
            'SELECT t.rule_id, y.id, y.name FROM fe_stock_rule_types t JOIN fe_device_types y ON y.id = t.device_type_id AND y.tenant_id = t.tenant_id'
            . ' WHERE t.tenant_id = ? ORDER BY NATURAL_SORT_KEY(y.name)',
        );
        $types->execute([$tenant->id]);
        $typesByRule = [];
        foreach ($types->fetchAll() as $row) {
            $typesByRule[(int) $row['rule_id']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        $locations = [];
        foreach ($rows as $row) {
            $locationId = (int) $row['location_id'];
            $have = (int) $row['have'];
            $min = (int) $row['min_count'];
            if (!isset($locations[$locationId])) {
                $name = (string) $row['name'];
                $locations[$locationId] = [
                    'location' => [
                        'id' => $locationId,
                        'name' => $name,
                        'path' => $row['parent_name'] === null ? $name : $row['parent_name'] . ' / ' . $name,
                        'level' => $row['parent_id'] === null ? 1 : 2,
                    ],
                    'rules' => [],
                    'out_of_service' => false,
                ];
            }
            $locations[$locationId]['rules'][] = [
                'id' => (int) $row['id'],
                'min_count' => $min,
                'types' => $typesByRule[(int) $row['id']] ?? [],
                'have' => $have,
                'missing' => max(0, $min - $have),
            ];
            $locations[$locationId]['out_of_service'] = $locations[$locationId]['out_of_service'] || $have < $min;
        }

        return array_values($locations);
    }

    /**
     * Ersetzt alle Regeln eines Standorts (leere Liste = keine Regeln).
     *
     * @param list<array{min_count: int, device_type_ids: list<int>}> $rules
     *
     * @throws LocationNotFoundException    unbekannt oder fremd
     * @throws DeviceRuleViolationException Standort gesperrt
     * @throws InvalidDeviceValueException  Regel ungültig oder Typ unbekannt
     */
    public function replace(Tenant $tenant, int $locationId, array $rules): void
    {
        if (count($rules) > self::MAX_RULES) {
            throw new InvalidDeviceValueException(sprintf('Ein Standort hat höchstens %d Regeln.', self::MAX_RULES));
        }
        foreach ($rules as $rule) {
            if ($rule['min_count'] < 1 || $rule['min_count'] > self::MAX_COUNT) {
                throw new InvalidDeviceValueException(sprintf('Die Soll-Anzahl muss zwischen 1 und %d liegen.', self::MAX_COUNT));
            }
            $ids = $rule['device_type_ids'];
            if ($ids === [] || count($ids) > self::MAX_TYPES || count(array_unique($ids)) !== count($ids)) {
                throw new InvalidDeviceValueException(sprintf('Jede Regel braucht 1 bis %d verschiedene Ausrüstungstypen.', self::MAX_TYPES));
            }
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT name, active FROM fe_locations WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $stmt->execute([$locationId, $tenant->id]);
            $location = $stmt->fetch();
            if (!is_array($location)) {
                throw LocationNotFoundException::forId($locationId);
            }
            if ((int) $location['active'] !== 1) {
                throw DeviceRuleViolationException::because(sprintf('„%s“ ist gesperrt; ein Sollbestand lässt sich dort nicht festlegen.', (string) $location['name']));
            }
            $known = $this->pdo->prepare('SELECT COUNT(*) FROM fe_device_types WHERE tenant_id = ? AND id = ?');
            foreach ($rules as $rule) {
                foreach ($rule['device_type_ids'] as $typeId) {
                    $known->execute([$tenant->id, $typeId]);
                    if ((int) $known->fetchColumn() !== 1) {
                        throw new InvalidDeviceValueException('Ein Ausrüstungstyp wurde nicht gefunden.');
                    }
                }
            }
            $this->pdo->prepare('DELETE FROM fe_stock_rules WHERE tenant_id = ? AND location_id = ?')->execute([$tenant->id, $locationId]);
            $insert = $this->pdo->prepare('INSERT INTO fe_stock_rules (tenant_id, location_id, min_count) VALUES (?, ?, ?)');
            $insertType = $this->pdo->prepare('INSERT INTO fe_stock_rule_types (rule_id, tenant_id, device_type_id) VALUES (?, ?, ?)');
            foreach ($rules as $rule) {
                $insert->execute([$tenant->id, $locationId, $rule['min_count']]);
                $ruleId = (int) $this->pdo->lastInsertId();
                foreach ($rule['device_type_ids'] as $typeId) {
                    $insertType->execute([$ruleId, $tenant->id, $typeId]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
