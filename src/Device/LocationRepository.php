<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Permission\DeviceVisibility;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Zugriff auf fe_locations, immer gefiltert nach Stadt. Standorte werden nie gelöscht, nur gesperrt;
 * verschieben und die Art ändern gibt es in Version 1 nicht. Die Datenbank erzwingt zwei Ebenen,
 * gleiche Art und Stadt (Fremdschlüssel) sowie eindeutige Namen je übergeordnetem Standort
 * (UNIQUE-Index, utf8mb4_unicode_ci); dieser Code prüft vorher mit verständlicher Meldung und
 * sichert, was die Datenbank nicht kann: Sperren nur leer, kein aktiver Unterstandort unter einem
 * gesperrten Standort.
 *
 * Sperren gegen gleichzeitige Änderungen (immer übergeordneter vor untergeordnetem Standort):
 * - Wer einen Standort als übergeordneten Standort oder als Ziel benutzt (Unterstandort anlegen,
 *   Unterstandort entsperren, künftig Geräte umbuchen), liest ihn mit LOCK IN SHARE MODE und prüft
 *   active. MariaDB 10.11 kennt kein FOR SHARE.
 * - lock() sperrt den Standort mit FOR UPDATE und zählt erst danach; wer ihn als Ziel benutzt,
 *   wartet also bzw. ist schon fertig und wird mitgezählt.
 *
 * Datenfilter (DeviceVisibility, Pflicht im Konstruktor): Gerätezahlen zählen nur sichtbare Geräte;
 * mit Standortfilter enthalten Baum und find() nur sichtbare Standorte, ein Standort der Ebene 1, von
 * dem nur Fächer sichtbar sind, erscheint mit diesen als nicht wählbar (selectable false). Ändern
 * (anlegen, umbenennen, sperren) erreichen nur Rollen ohne Datenfilter (F3); lock() zählt immer alle
 * Geräte.
 */
final readonly class LocationRepository
{
    private const int ER_DUP_ENTRY = 1062;

    public function __construct(private \PDO $pdo, private DeviceVisibility $visibility)
    {
    }

    /**
     * Baumstruktur: Standorte der Ebene 1 mit ihren Unterstandorten, jeweils nach Name.
     *
     * @param bool $includeInactive auch gesperrte Standorte (sonst ohne sie und ohne ihre
     *                              Unterstandorte)
     *
     * @return list<Location>
     */
    public function tree(Tenant $tenant, bool $includeInactive): array
    {
        $tree = $this->visible($this->build($this->fetchRows($tenant, '', [])));
        if ($includeInactive) {
            return $tree;
        }

        $active = array_map(
            static fn (Location $l): Location => $l->withActiveChildrenOnly(),
            array_filter($tree, static fn (Location $l): bool => $l->active),
        );

        // Nicht wählbar und ohne aktive sichtbare Fächer: nichts zu zeigen.
        return array_values(array_filter($active, static fn (Location $l): bool => $l->selectable || $l->children !== []));
    }

    /**
     * Ein Standort; bei Ebene 1 mit allen Unterstandorten (auch gesperrten).
     */
    public function find(Tenant $tenant, int $id): ?Location
    {
        $rows = $this->fetchRows($tenant, ' AND (l.id = ? OR l.parent_id = ?)', [$id, $id]);
        foreach ($this->visible($this->build($rows)) as $location) {
            if ($location->id === $id) {
                return $location;
            }
        }

        // Unterstandort: build() liefert nur Ebene 1, die hier fehlt.
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $this->visibility->allowsLocation($id) ? self::hydrate($row, []) : null;
            }
        }

        return null;
    }

    /**
     * Legt einen aktiven Standort an. Mit $parentId einen Unterstandort: Er übernimmt die Art des
     * übergeordneten Standorts ($kind dann null oder dieselbe); dieser muss auf Ebene 1 liegen und
     * aktiv sein.
     *
     * @param LocationKind|null $kind Pflicht ohne $parentId
     *
     * @throws InvalidDeviceValueException  Art fehlt, übergeordneter Standort unbekannt oder fremd
     * @throws DeviceRuleViolationException zu tief, gesperrt, andere Art
     * @throws DuplicateDeviceNameException
     */
    public function create(Tenant $tenant, LocationName $name, ?LocationKind $kind, ?int $parentId): Location
    {
        return $this->transaction(function () use ($tenant, $name, $kind, $parentId): int {
            $parent = null;
            if ($parentId !== null) {
                $parent = $this->lockRow($tenant, $parentId, 'LOCK IN SHARE MODE')
                    ?? throw new InvalidDeviceValueException('Der übergeordnete Standort wurde nicht gefunden.');
                $parentName = LocationName::fromString((string) $parent['name']);
                $parentKind = LocationKind::from((string) $parent['kind']);
                if ($parent['parent_id'] !== null) {
                    throw DeviceRuleViolationException::tooDeep($parentName);
                }
                if ((int) $parent['active'] !== 1) {
                    throw DeviceRuleViolationException::parentLocked($parentName);
                }
                if ($kind !== null && $kind !== $parentKind) {
                    throw DeviceRuleViolationException::kindMismatch($parentName, $parentKind);
                }
                $kind = $parentKind;
            }
            if ($kind === null) {
                throw new InvalidDeviceValueException('Ein Standort der obersten Ebene braucht eine Art (vehicle, room oder external).');
            }

            try {
                $this->pdo->prepare(
                    'INSERT INTO fe_locations (tenant_id, kind, name, parent_id, level, parent_level, active, created_at, updated_at)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                )->execute([
                    $tenant->id,
                    $kind->value,
                    $name->value,
                    $parentId,
                    $parentId === null ? 1 : 2,
                    $parentId === null ? null : 1,
                ]);
            } catch (\PDOException $e) {
                throw self::isDuplicate($e)
                    ? DuplicateDeviceNameException::location($name, $parent === null ? null : LocationName::fromString((string) $parent['name']))
                    : $e;
            }

            return (int) $this->pdo->lastInsertId();
        }, $tenant);
    }

    /**
     * Groß- und Kleinschreibung zählt als Änderung (z. B. „hlf 20“ → „HLF 20“).
     *
     * @throws LocationNotFoundException
     * @throws DuplicateDeviceNameException
     */
    public function rename(Tenant $tenant, int $id, LocationName $name): Location
    {
        $location = $this->find($tenant, $id) ?? throw LocationNotFoundException::forId($id);
        try {
            $this->pdo->prepare(
                'UPDATE fe_locations SET name = ?, updated_at = UTC_TIMESTAMP()'
                . ' WHERE id = ? AND tenant_id = ? AND name COLLATE utf8mb4_bin <> ?',
            )->execute([$name->value, $id, $tenant->id, $name->value]);
        } catch (\PDOException $e) {
            if (!self::isDuplicate($e)) {
                throw $e;
            }
            $parent = $location->parentId === null ? null : $this->find($tenant, $location->parentId);
            throw DuplicateDeviceNameException::location($name, $parent?->name);
        }

        return $this->find($tenant, $id) ?? throw LocationNotFoundException::forId($id);
    }

    /**
     * Sperrt einen Standort, nur wenn er leer ist: keine nicht ausgesonderten Geräte, keine aktiven
     * Unterstandorte. Wiederholbar (schon gesperrt: keine Prüfung, keine Änderung).
     *
     * @throws LocationNotFoundException
     * @throws DeviceRuleViolationException mit details {devices, sublocations}
     */
    public function lock(Tenant $tenant, int $id): Location
    {
        return $this->transaction(function () use ($tenant, $id): int {
            $row = $this->lockRow($tenant, $id, 'FOR UPDATE') ?? throw LocationNotFoundException::forId($id);
            if ((int) $row['active'] !== 1) {
                return $id;
            }

            // Erst nach FOR UPDATE gezählt (erstes lesendes Statement nach der Sperre, also aktueller Stand).
            $devices = $this->count(
                "SELECT COUNT(*) FROM fe_devices WHERE tenant_id = ? AND location_id = ? AND status <> 'retired'",
                [$tenant->id, $id],
            );
            $sublocations = $this->count(
                'SELECT COUNT(*) FROM fe_locations WHERE tenant_id = ? AND parent_id = ? AND active = 1',
                [$tenant->id, $id],
            );
            if ($devices > 0 || $sublocations > 0) {
                throw DeviceRuleViolationException::notEmpty(LocationName::fromString((string) $row['name']), $devices, $sublocations);
            }

            $this->setActive($tenant, $id, false);

            return $id;
        }, $tenant);
    }

    /**
     * Entsperrt einen Standort; ein Unterstandort nur, wenn der übergeordnete aktiv ist. Wiederholbar.
     * Unterstandorte eines entsperrten Standorts bleiben gesperrt.
     *
     * @throws LocationNotFoundException
     * @throws DeviceRuleViolationException
     */
    public function unlock(Tenant $tenant, int $id): Location
    {
        return $this->transaction(function () use ($tenant, $id): int {
            // parent_id ist unveränderlich (kein Verschieben), darf also ohne Sperre gelesen werden.
            $stmt = $this->pdo->prepare('SELECT parent_id FROM fe_locations WHERE id = ? AND tenant_id = ?');
            $stmt->execute([$id, $tenant->id]);
            $parentId = $stmt->fetchColumn();
            if ($parentId === false) {
                throw LocationNotFoundException::forId($id);
            }

            $parent = $parentId === null ? null : $this->lockRow($tenant, (int) $parentId, 'LOCK IN SHARE MODE');
            $row = $this->lockRow($tenant, $id, 'FOR UPDATE') ?? throw LocationNotFoundException::forId($id);
            if ((int) $row['active'] === 1) {
                return $id;
            }
            if ($parent !== null && (int) $parent['active'] !== 1) {
                throw DeviceRuleViolationException::parentInactive(LocationName::fromString((string) $parent['name']));
            }

            $this->setActive($tenant, $id, true);

            return $id;
        }, $tenant);
    }

    /**
     * Setzt die Aufgabe eines Standorts der Ebene 1 ($task null = entfernen). Nur freigeschaltete
     * Aufgaben (LocationTaskRepository). Das erste Lager wird zum Standard-Lager; wird ein anderes
     * als Standard gewählt, verliert das bisherige die Markierung. Wiederholbar.
     *
     * @param string|null $collectionAccepts used, defective oder both (Sammelstelle, Standard both)
     *
     * @throws LocationNotFoundException
     * @throws InvalidDeviceValueException  ungültige Annahme
     * @throws DeviceRuleViolationException Unterstandort, Aufgabe nicht freigeschaltet
     */
    public function setTask(Tenant $tenant, int $id, ?LocationTask $task, bool $defaultStore, ?string $collectionAccepts): Location
    {
        if ($collectionAccepts !== null && !in_array($collectionAccepts, ['used', 'defective', 'both'], true)) {
            throw new InvalidDeviceValueException('Das Feld "collection_accepts" muss "used", "defective" oder "both" sein.');
        }

        return $this->transaction(function () use ($tenant, $id, $task, $defaultStore, $collectionAccepts): int {
            $row = $this->lockRow($tenant, $id, 'FOR UPDATE') ?? throw LocationNotFoundException::forId($id);
            $name = LocationName::fromString((string) $row['name']);
            if ($task !== LocationTask::Borrower) {
                // Verliehene Ausrüstung braucht den Leihnehmer (Phase 2, P2.5): erst zurückbuchen.
                $open = $this->count(
                    'SELECT COUNT(*) FROM fe_device_loans l JOIN fe_devices d ON d.id = l.device_id AND d.tenant_id = l.tenant_id'
                    . " WHERE l.tenant_id = ? AND l.borrower_location_id = ? AND l.returned_at IS NULL AND d.status <> 'retired' AND d.location_id = l.borrower_location_id",
                    [$tenant->id, $id],
                );
                if ($open > 0) {
                    throw DeviceRuleViolationException::because(sprintf('Bei „%s“ %s noch verliehen. Bitte zuerst die Rückgabe buchen.', $name->value, $open === 1 ? 'ist 1 Ausrüstung' : sprintf('sind %d Ausrüstungen', $open)), ['loans' => $open]);
                }
            }
            if ($task !== null) {
                if ($row['parent_id'] !== null) {
                    throw DeviceRuleViolationException::because(sprintf('Eine Aufgabe kann nur ein Standort der obersten Ebene haben; „%s“ ist ein Unterstandort.', $name->value));
                }
                if (!new LocationTaskRepository($this->pdo)->isEnabled($tenant, $task)) {
                    throw DeviceRuleViolationException::because(sprintf('Die Aufgabe „%s“ ist für diese Stadt nicht freigeschaltet.', $task->label()));
                }
            }

            $isDefault = $task === LocationTask::Store && $defaultStore;
            if ($task === LocationTask::Store && !$isDefault) {
                // Das erste Lager der Stadt wird zum Standard-Lager.
                $stmt = $this->pdo->prepare('SELECT id FROM fe_locations WHERE tenant_id = ? AND is_default_store = 1 AND id <> ? FOR UPDATE');
                $stmt->execute([$tenant->id, $id]);
                $isDefault = $stmt->fetchAll() === [];
            }
            if ($isDefault) {
                $this->pdo->prepare('UPDATE fe_locations SET is_default_store = 0, updated_at = UTC_TIMESTAMP() WHERE tenant_id = ? AND is_default_store = 1 AND id <> ?')
                    ->execute([$tenant->id, $id]);
            }
            $this->pdo->prepare(
                'UPDATE fe_locations SET task = ?, is_default_store = ?, collection_accepts = ?, updated_at = UTC_TIMESTAMP()'
                . ' WHERE id = ? AND tenant_id = ?',
            )->execute([
                $task?->value,
                (int) $isDefault,
                $task === LocationTask::Collection ? ($collectionAccepts ?? 'both') : null,
                $id,
                $tenant->id,
            ]);

            return $id;
        }, $tenant);
    }

    private function setActive(Tenant $tenant, int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE fe_locations SET active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ?')
            ->execute([(int) $active, $id, $tenant->id]);
    }

    /**
     * Führt $work in einer eigenen Transaktion aus (eine bereits offene ist ein Fehler) und liefert
     * danach den Standort mit der zurückgegebenen ID.
     *
     * @param \Closure(): int $work
     */
    private function transaction(\Closure $work, Tenant $tenant): Location
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('LocationRepository startet eine eigene Transaktion; keine offene Transaktion erlaubt.');
        }

        $this->pdo->beginTransaction();
        try {
            $id = $work();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($tenant, $id) ?? throw new \LogicException('Standort nach der Änderung nicht gefunden.');
    }

    /**
     * @param 'FOR UPDATE'|'LOCK IN SHARE MODE' $mode
     *
     * @return array<string, mixed>|null
     */
    private function lockRow(Tenant $tenant, int $id, string $mode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, kind, parent_id, active FROM fe_locations WHERE id = ? AND tenant_id = ? ' . $mode);
        $stmt->execute([$id, $tenant->id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<int> $params
     */
    private function count(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Die Sammelstelle, in die „Benutzt melden“ bucht (Phase 2, P2.3, E8): der aktive Standort mit der
     * Aufgabe Sammelstelle, der benutzte Ausrüstung annimmt (`used` oder `both`), der mit der
     * kleinsten ID. Unabhängig vom Datenfilter (der Ablauf gibt das Ziel vor, G4). null = keine.
     */
    public function collectionPointForUsed(Tenant $tenant): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM fe_locations WHERE tenant_id = ? AND task = 'collection' AND active = 1"
            . " AND collection_accepts IN ('used', 'both') ORDER BY id LIMIT 1",
        );
        $stmt->execute([$tenant->id]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Wie {@see collectionPointForUsed()}, mit dem Namen (für die Rückfrage der App).
     *
     * @return array{id: int, name: string}|null
     */
    public function collectionPointForUsedWithName(Tenant $tenant): ?array
    {
        $id = $this->collectionPointForUsed($tenant);
        if ($id === null) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT name FROM fe_locations WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenant->id]);
        $name = $stmt->fetchColumn();

        return $name === false ? null : ['id' => $id, 'name' => (string) $name];
    }

    /**
     * Aktive Standorte der Ebene 1 mit dieser Aufgabe (Name, ID), nach ID; für die Seite „Wartung“ und
     * die Ziele ihrer Sammelaktionen. Unabhängig vom Datenfilter (Phase 2, G4). Mit $defaultStoreOnly
     * nur das Standard-Lager.
     *
     * @return list<array{id: int, name: string}>
     */
    public function withTask(Tenant $tenant, LocationTask $task, bool $defaultStoreOnly = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM fe_locations WHERE tenant_id = ? AND task = ? AND active = 1'
            . ($defaultStoreOnly ? ' AND is_default_store = 1' : '') . ' ORDER BY id',
        );
        $stmt->execute([$tenant->id, $task->value]);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $stmt->fetchAll(),
        );
    }

    /**
     * @param string    $where SQL aus diesem Code, nie aus Eingaben
     * @param list<int> $params
     *
     * @return list<array<string, mixed>> nach Name
     */
    private function fetchRows(Tenant $tenant, string $where, array $params): array
    {
        // Gezählt werden nur sichtbare Geräte.
        [$visible, $visibleParams] = $this->visibility->sql('d');
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.name, l.kind, l.parent_id, l.active, l.task, l.is_default_store, l.collection_accepts, l.created_at, l.updated_at, COALESCE(c.devices, 0) AS devices'
            . ' FROM fe_locations l LEFT JOIN ('
            . "SELECT d.location_id, COUNT(*) AS devices FROM fe_devices d WHERE d.tenant_id = ? AND d.status <> 'retired'" . $visible
            . ' GROUP BY d.location_id'
            . ') c ON c.location_id = l.id'
            . ' WHERE l.tenant_id = ?' . $where
            . ' ORDER BY l.name, l.id',
        );
        $stmt->execute([$tenant->id, ...$visibleParams, $tenant->id, ...$params]);

        return $stmt->fetchAll();
    }

    /**
     * Nur sichtbare Standorte (ohne Standortfilter alle): Fächer im Filter; Ebene 1 im Filter, sonst
     * nur mit sichtbaren Fächern und dann nicht wählbar.
     *
     * @param list<Location> $tree
     *
     * @return list<Location>
     */
    private function visible(array $tree): array
    {
        if (!$this->visibility->filtersLocations()) {
            return $tree;
        }

        $visible = [];
        foreach ($tree as $location) {
            $children = array_values(array_filter($location->children, fn (Location $c): bool => $this->visibility->allowsLocation($c->id)));
            $selectable = $this->visibility->allowsLocation($location->id);
            if ($selectable || $children !== []) {
                $visible[] = $location->withChildren($children, $selectable);
            }
        }

        return $visible;
    }

    /**
     * @param list<array<string, mixed>> $rows nach Name
     *
     * @return list<Location> Ebene 1 mit Unterstandorten
     */
    private function build(array $rows): array
    {
        $children = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] !== null) {
                $children[(int) $row['parent_id']][] = self::hydrate($row, []);
            }
        }

        $tree = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] === null) {
                $tree[] = self::hydrate($row, $children[(int) $row['id']] ?? []);
            }
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<Location>       $children
     */
    private static function hydrate(array $row, array $children): Location
    {
        $devices = (int) $row['devices'];

        return new Location(
            (int) $row['id'],
            LocationName::fromString((string) $row['name']),
            LocationKind::from((string) $row['kind']),
            $row['parent_id'] === null ? null : (int) $row['parent_id'],
            (int) $row['active'] === 1,
            $devices,
            $devices + array_sum(array_map(static fn (Location $c): int => $c->deviceCount, $children)),
            $children,
            self::utc((string) $row['created_at']),
            self::utc((string) $row['updated_at']),
            true,
            $row['task'] === null ? null : LocationTask::from((string) $row['task']),
            (int) $row['is_default_store'] === 1,
            $row['collection_accepts'] === null ? null : (string) $row['collection_accepts'],
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
