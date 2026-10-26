<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Zugriff auf fe_devices, immer gefiltert nach Stadt. Geräte werden nie gelöscht (es gibt bewusst
 * keine Methode dafür). Jede Änderung schreibt in derselben Transaktion ihr Ereignis
 * (DeviceEventWriter) und erhöht den Versionszähler einmal; eine veraltete erwartete Version wird
 * abgelehnt (DeviceVersionConflictException), ohne etwas zu schreiben. Ob eine Kennung vergeben
 * ist, entscheidet der UNIQUE-Index auf identifier_key (Fehler 1062), nicht eine Vorabfrage.
 *
 * Sperren gegen gleichzeitige Änderungen (siehe LocationRepository): Ziel-Standort und Gerätetyp
 * werden mit LOCK IN SHARE MODE gelesen und erst dann auf active geprüft, bevor das Gerät
 * geschrieben wird (Anlegen, Umbuchen, Wiederaufnehmen). Änderungen sperren zuerst das Gerät
 * (FOR UPDATE), dann ggf. den Ziel-Standort, dann ggf. den Gerätetyp.
 */
final readonly class DeviceRepository
{
    private const int ER_DUP_ENTRY = 1062;

    /** Spalte in fe_devices je Stammdatenfeld */
    private const array COLUMNS = [
        'identifier' => 'identifier',
        'device_type' => 'device_type_id',
        'serial_number' => 'serial_number',
        'manufacturer' => 'manufacturer',
        'model' => 'model',
        'procured_on' => 'procured_on',
        'note' => 'note',
    ];

    private const string SELECT = 'SELECT d.id, d.identifier, d.device_type_id, t.name AS type_name, d.location_id,'
        . ' l.name AS location_name, p.name AS parent_name, d.status, d.serial_number, d.manufacturer, d.model,'
        . ' d.procured_on, d.next_inspection_due, d.note, d.version, d.created_at, d.updated_at';

    private const string FROM = ' FROM fe_devices d'
        . ' JOIN fe_device_types t ON t.id = d.device_type_id AND t.tenant_id = d.tenant_id'
        . ' JOIN fe_locations l ON l.id = d.location_id AND l.tenant_id = d.tenant_id'
        . ' LEFT JOIN fe_locations p ON p.id = l.parent_id AND p.tenant_id = d.tenant_id'
        . ' WHERE d.tenant_id = ?';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Eine Seite der Liste mit Gesamtzahl.
     */
    public function list(Tenant $tenant, DeviceFilter $filter, DeviceSort $sort, int $limit, int $offset): DevicePage
    {
        if ($limit < 1 || $offset < 0) {
            throw new \InvalidArgumentException('limit muss positiv, offset nicht negativ sein.');
        }

        [$where, $params] = self::where($filter);
        $params = [$tenant->id, ...$params];

        $count = $this->pdo->prepare('SELECT COUNT(*)' . self::FROM . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $order = match ($sort) {
            DeviceSort::Identifier => 'NATURAL_SORT_KEY(d.identifier), d.id',
            DeviceSort::NextInspectionDue => 'd.next_inspection_due IS NULL, d.next_inspection_due, NATURAL_SORT_KEY(d.identifier), d.id',
        };
        // limit und offset sind geprüfte Ganzzahlen.
        $stmt = $this->pdo->prepare(sprintf('%s%s%s ORDER BY %s LIMIT %d OFFSET %d', self::SELECT, self::FROM, $where, $order, $limit, $offset));
        $stmt->execute($params);

        return new DevicePage(array_map(self::hydrate(...), $stmt->fetchAll()), $total);
    }

    /**
     * Ein Abschnitt der Übersicht der Prüffristen (höchstens $limit Geräte) mit Gesamtzahl. Der
     * Filter darf nur Gerätetyp und Standort enthalten; ausgesonderte Geräte sind nie dabei. „Heute“
     * kommt als Parameter aus $window (deutsche Zeit), nie aus CURDATE() (Sitzung in UTC).
     */
    public function listInspections(Tenant $tenant, DeviceFilter $filter, InspectionWindow $window, InspectionSection $section, int $limit): DevicePage
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('limit muss positiv sein.');
        }
        if ($filter->statuses !== [] || $filter->includeRetired || $filter->search !== null) {
            throw new \InvalidArgumentException('Die Prüffristen filtern nur nach Gerätetyp und Standort.');
        }

        [$where, $params] = self::where($filter);
        [$condition, $order, $dates] = match ($section) {
            InspectionSection::Overdue => [' AND d.next_inspection_due < ?', 'd.next_inspection_due, NATURAL_SORT_KEY(d.identifier), d.id', [$window->today]],
            InspectionSection::DueSoon => [' AND d.next_inspection_due BETWEEN ? AND ?', 'd.next_inspection_due, NATURAL_SORT_KEY(d.identifier), d.id', [$window->today, $window->until]],
            InspectionSection::WithoutDate => [' AND d.next_inspection_due IS NULL', 'NATURAL_SORT_KEY(d.identifier), d.id', []],
        };
        $where .= $condition;
        $params = [$tenant->id, ...$params, ...$dates];

        $count = $this->pdo->prepare('SELECT COUNT(*)' . self::FROM . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        // limit ist eine geprüfte Ganzzahl.
        $stmt = $this->pdo->prepare(sprintf('%s%s%s ORDER BY %s LIMIT %d', self::SELECT, self::FROM, $where, $order, $limit));
        $stmt->execute($params);

        return new DevicePage(array_map(self::hydrate(...), $stmt->fetchAll()), $total);
    }

    /**
     * Zahlen für die Startseite in einer Abfrage (ohne ausgesonderte Geräte): je Status sowie
     * überfällig, bald fällig und ohne Prüfdatum laut $window.
     */
    public function summary(Tenant $tenant, InspectionWindow $window): DeviceSummary
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.status, COUNT(*) AS devices, SUM(d.next_inspection_due < ?) AS overdue,'
            . ' SUM(d.next_inspection_due BETWEEN ? AND ?) AS due_soon, SUM(d.next_inspection_due IS NULL) AS without_date'
            . " FROM fe_devices d WHERE d.tenant_id = ? AND d.status <> 'retired' GROUP BY d.status",
        );
        $stmt->execute([$window->today, $window->today, $window->until, $tenant->id]);

        $byStatus = [];
        foreach (DeviceStatus::cases() as $status) {
            if ($status !== DeviceStatus::Retired) {
                $byStatus[$status->value] = 0;
            }
        }
        $overdue = $dueSoon = $withoutDate = 0;
        foreach ($stmt->fetchAll() as $row) {
            $byStatus[(string) $row['status']] = (int) $row['devices'];
            $overdue += (int) $row['overdue'];
            $dueSoon += (int) $row['due_soon'];
            $withoutDate += (int) $row['without_date'];
        }

        return new DeviceSummary($window, $byStatus, $overdue, $dueSoon, $withoutDate);
    }

    public function find(Tenant $tenant, int $id): ?Device
    {
        $stmt = $this->pdo->prepare(self::SELECT . self::FROM . ' AND d.id = ?');
        $stmt->execute([$tenant->id, $id]);
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Legt ein Gerät an und schreibt das Ereignis created.
     *
     * @param int $userId Konto der Stadt, das anlegt
     *
     * @throws InvalidDeviceValueException          Standort oder Gerätetyp unbekannt oder fremd
     * @throws DeviceRuleViolationException         Standort gesperrt, Gerätetyp deaktiviert
     * @throws DuplicateDeviceIdentifierException
     */
    public function create(Tenant $tenant, int $userId, NewDevice $device, ?string $comment): Device
    {
        return $this->transaction(function () use ($tenant, $userId, $device, $comment): int {
            $this->lockActiveLocation($tenant, $device->locationId);
            $this->lockActiveDeviceType($tenant, $device->deviceTypeId);

            try {
                $this->pdo->prepare(
                    'INSERT INTO fe_devices (tenant_id, identifier, device_type_id, location_id, status, serial_number,'
                    . ' manufacturer, model, procured_on, next_inspection_due, note, version, created_at, updated_at)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                )->execute([
                    $tenant->id,
                    $device->identifier->value,
                    $device->deviceTypeId,
                    $device->locationId,
                    $device->status->value,
                    $device->serialNumber,
                    $device->manufacturer,
                    $device->model,
                    $device->procuredOn?->value,
                    $device->nextInspectionDue?->value,
                    $device->note,
                ]);
            } catch (\PDOException $e) {
                throw self::isDuplicate($e) ? DuplicateDeviceIdentifierException::forIdentifier($device->identifier) : $e;
            }
            $id = (int) $this->pdo->lastInsertId();

            new DeviceEventWriter($this->pdo)->created($tenant, $id, $userId, $device->locationId, $device->status, $comment);

            return $id;
        }, $tenant);
    }

    /**
     * Ändert Stammdaten: je Feld, dessen Wert sich wirklich ändert (binär verglichen, „ma-01“ →
     * „MA-01“ zählt), ein Ereignis field_changed mit demselben Kommentar; die Version steigt einmal.
     * Ändert sich nichts, bleibt alles unverändert (auch die Version).
     *
     * @throws DeviceNotFoundException
     * @throws DeviceVersionConflictException
     * @throws DeviceRuleViolationException       ausgesondert, Gerätetyp deaktiviert
     * @throws InvalidDeviceValueException        Gerätetyp unbekannt oder fremd
     * @throws DuplicateDeviceIdentifierException
     */
    public function updateFields(Tenant $tenant, int $userId, int $id, int $expectedVersion, DeviceChanges $changes, ?string $comment): Device
    {
        return $this->transaction(function () use ($tenant, $userId, $id, $expectedVersion, $changes, $comment): int {
            $current = $this->lockForChange($tenant, $id, $expectedVersion);
            if ($current->isRetired()) {
                throw DeviceRuleViolationException::deviceRetired($current->identifier);
            }

            /** @var array<string, array{?string, ?string, int|string|null}> $changed Feld => [alt, neu, Spaltenwert] */
            $changed = [];
            foreach (DeviceField::cases() as $field) {
                if (!$changes->has($field)) {
                    continue;
                }
                [$new, $column] = match ($field) {
                    DeviceField::Identifier => [$changes->identifier()?->value, $changes->identifier()?->value],
                    DeviceField::DeviceType => $this->newDeviceType($tenant, $current, (int) $changes->deviceTypeId()),
                    DeviceField::ProcuredOn => [$changes->procuredOn()?->value, $changes->procuredOn()?->value],
                    default => [$changes->text($field), $changes->text($field)],
                };
                $old = $current->fieldText($field);
                if ($old !== $new) {
                    $changed[$field->value] = [$old, $new, $column];
                }
            }
            if ($changed === []) {
                return $id;
            }

            $set = implode(', ', array_map(static fn (string $f): string => self::COLUMNS[$f] . ' = ?', array_keys($changed)));
            try {
                $this->pdo->prepare('UPDATE fe_devices SET ' . $set . ', version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ?')
                    ->execute([...array_column($changed, 2), $id, $tenant->id]);
            } catch (\PDOException $e) {
                throw self::isDuplicate($e) && $changes->identifier() !== null
                    ? DuplicateDeviceIdentifierException::forIdentifier($changes->identifier())
                    : $e;
            }

            $events = new DeviceEventWriter($this->pdo);
            foreach ($changed as $field => [$old, $new]) {
                $events->fieldChanged($tenant, $id, $userId, DeviceField::from($field), $old, $new, $comment);
            }

            return $id;
        }, $tenant);
    }

    /**
     * Setzt (oder entfernt mit null) die nächste fällige Prüfung; Ereignis due_changed. Ändert sich
     * nichts, bleibt alles unverändert (auch die Version).
     *
     * @throws DeviceNotFoundException
     * @throws DeviceVersionConflictException
     * @throws DeviceRuleViolationException   ausgesondert
     */
    public function setInspectionDue(Tenant $tenant, int $userId, int $id, int $expectedVersion, ?DeviceDate $due, ?string $comment): Device
    {
        return $this->transaction(function () use ($tenant, $userId, $id, $expectedVersion, $due, $comment): int {
            $current = $this->lockForChange($tenant, $id, $expectedVersion);
            if ($current->isRetired()) {
                throw DeviceRuleViolationException::deviceRetired($current->identifier);
            }
            if ($current->nextInspectionDue?->value === $due?->value) {
                return $id;
            }

            $this->pdo->prepare('UPDATE fe_devices SET next_inspection_due = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ?')
                ->execute([$due?->value, $id, $tenant->id]);
            new DeviceEventWriter($this->pdo)->dueChanged($tenant, $id, $userId, $current->nextInspectionDue, $due, $comment);

            return $id;
        }, $tenant);
    }

    /**
     * Bucht um und/oder ändert den Status (nie auf ausgesondert): ein Ereignis state_changed mit den
     * Paaren, die sich ändern; die Version steigt einmal. Gleicher Standort bzw. Status wird
     * ignoriert; ändert sich nichts, bleibt alles unverändert (auch die Version). Der Ziel-Standort
     * wird nur bei einem Wechsel gelesen (LOCK IN SHARE MODE) und muss aktiv sein.
     *
     * @throws DeviceNotFoundException
     * @throws DeviceVersionConflictException
     * @throws DeviceRuleViolationException   ausgesondert, Ziel-Standort gesperrt
     * @throws InvalidDeviceValueException    Ziel-Standort unbekannt oder fremd
     */
    public function changeState(Tenant $tenant, int $userId, int $id, int $expectedVersion, DeviceStateChange $change, ?string $comment): Device
    {
        return $this->transaction(function () use ($tenant, $userId, $id, $expectedVersion, $change, $comment): int {
            $current = $this->lockForChange($tenant, $id, $expectedVersion);
            if ($current->isRetired()) {
                throw DeviceRuleViolationException::retiredNotMovable($current->identifier);
            }

            $locationId = $change->locationId ?? $current->locationId;
            $status = $change->status ?? $current->status;
            if ($locationId === $current->locationId && $status === $current->status) {
                return $id;
            }
            if ($locationId !== $current->locationId) {
                $this->lockActiveLocation($tenant, $locationId);
            }
            $this->applyState($tenant, $userId, $current, $locationId, $status, [], $comment);

            return $id;
        }, $tenant);
    }

    /**
     * Sondert ein Gerät aus (Status retired, Ereignis state_changed). Standort und Prüfdatum bleiben
     * gespeichert; die Kennung wird frei (identifier_key NULL).
     *
     * @throws DeviceNotFoundException
     * @throws DeviceVersionConflictException
     * @throws DeviceRuleViolationException   bereits ausgesondert
     */
    public function retire(Tenant $tenant, int $userId, int $id, int $expectedVersion, ?string $comment): Device
    {
        return $this->transaction(function () use ($tenant, $userId, $id, $expectedVersion, $comment): int {
            $current = $this->lockForChange($tenant, $id, $expectedVersion);
            if ($current->isRetired()) {
                throw DeviceRuleViolationException::alreadyRetired($current->identifier);
            }
            $this->applyState($tenant, $userId, $current, $current->locationId, DeviceStatus::Retired, [], $comment);

            return $id;
        }, $tenant);
    }

    /**
     * Nimmt ein ausgesondertes Gerät wieder auf: neuer Status; Standort (angegeben oder bisheriger)
     * und Gerätetyp (angegeben oder bisheriger) werden mit LOCK IN SHARE MODE gelesen und müssen
     * aktiv sein. Optional neue Kennung bzw. neuer Gerätetyp: je geändertem Feld ein Ereignis
     * field_changed, danach state_changed (alle mit demselben Kommentar); die Version steigt einmal.
     * Gleiche Werte werden ignoriert. Ist die Kennung inzwischen vergeben, entscheidet der
     * UNIQUE-Index (Fehler 1062).
     *
     * @throws DeviceNotFoundException
     * @throws DeviceVersionConflictException
     * @throws DeviceRuleViolationException       nicht ausgesondert, Standort gesperrt, Gerätetyp deaktiviert
     * @throws InvalidDeviceValueException        Standort oder Gerätetyp unbekannt oder fremd
     * @throws DuplicateDeviceIdentifierException
     */
    public function reinstate(Tenant $tenant, int $userId, int $id, int $expectedVersion, DeviceReinstatement $reinstatement, ?string $comment): Device
    {
        return $this->transaction(function () use ($tenant, $userId, $id, $expectedVersion, $reinstatement, $comment): int {
            $current = $this->lockForChange($tenant, $id, $expectedVersion);
            if (!$current->isRetired()) {
                throw DeviceRuleViolationException::notRetired($current->identifier);
            }

            $locationId = $reinstatement->locationId ?? $current->locationId;
            if ($locationId !== $current->locationId) {
                $this->lockActiveLocation($tenant, $locationId);
            } else {
                $location = $this->lockLocation($tenant, $locationId) ?? throw new \LogicException('Standort des Geräts fehlt.');
                if ((int) $location['active'] !== 1) {
                    throw DeviceRuleViolationException::reinstateLocationLocked(LocationName::fromString((string) $location['name']));
                }
            }

            /** @var array<string, array{?string, ?string, int|string|null}> $changed Feld => [alt, neu, Spaltenwert] */
            $changed = [];
            $identifier = $reinstatement->identifier;
            if ($identifier !== null && $identifier->value !== $current->identifier->value) {
                $changed[DeviceField::Identifier->value] = [$current->identifier->value, $identifier->value, $identifier->value];
            }
            $typeId = $reinstatement->deviceTypeId ?? $current->deviceTypeId;
            if ($typeId !== $current->deviceTypeId) {
                $changed[DeviceField::DeviceType->value] = [$current->deviceTypeName, $this->lockActiveDeviceType($tenant, $typeId), $typeId];
            } else {
                [$typeName, $active] = $this->lockDeviceType($tenant, $typeId);
                if (!$active) {
                    throw DeviceRuleViolationException::reinstateDeviceTypeInactive(DeviceTypeName::fromString($typeName));
                }
            }

            try {
                $this->applyState($tenant, $userId, $current, $locationId, $reinstatement->status, $changed, $comment);
            } catch (\PDOException $e) {
                if (!self::isDuplicate($e)) {
                    throw $e;
                }
                throw $identifier !== null && isset($changed[DeviceField::Identifier->value])
                    ? DuplicateDeviceIdentifierException::forIdentifier($identifier)
                    : DuplicateDeviceIdentifierException::onReinstatement($current->identifier);
            }

            return $id;
        }, $tenant);
    }

    /**
     * Schreibt Standort, Status und ggf. Stammdaten in einem UPDATE (Version + 1), danach je Feld ein
     * Ereignis field_changed und zuletzt state_changed mit den Paaren, die sich ändern.
     *
     * @param array<string, array{?string, ?string, int|string|null}> $fields Feld => [alt, neu, Spaltenwert]
     */
    private function applyState(Tenant $tenant, int $userId, Device $current, int $locationId, DeviceStatus $status, array $fields, ?string $comment): void
    {
        $set = implode('', array_map(static fn (string $f): string => ', ' . self::COLUMNS[$f] . ' = ?', array_keys($fields)));
        $this->pdo->prepare('UPDATE fe_devices SET location_id = ?, status = ?' . $set . ', version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ?')
            ->execute([$locationId, $status->value, ...array_column($fields, 2), $current->id, $tenant->id]);

        $events = new DeviceEventWriter($this->pdo);
        foreach ($fields as $field => [$old, $new]) {
            $events->fieldChanged($tenant, $current->id, $userId, DeviceField::from($field), $old, $new, $comment);
        }
        $moved = $locationId !== $current->locationId;
        $statusChanged = $status !== $current->status;
        $events->stateChanged(
            $tenant,
            $current->id,
            $userId,
            $moved ? $current->locationId : null,
            $moved ? $locationId : null,
            $statusChanged ? $current->status : null,
            $statusChanged ? $status : null,
            $comment,
        );
    }

    /**
     * Sperrt das Gerät (FOR UPDATE) und prüft die Version. Ob das Gerät ausgesondert sein darf,
     * prüft danach die jeweilige Änderung.
     *
     * @throws DeviceNotFoundException
     * @throws DeviceVersionConflictException
     */
    private function lockForChange(Tenant $tenant, int $id, int $expectedVersion): Device
    {
        $stmt = $this->pdo->prepare('SELECT id FROM fe_devices WHERE id = ? AND tenant_id = ? FOR UPDATE');
        $stmt->execute([$id, $tenant->id]);
        if ($stmt->fetchColumn() === false) {
            throw DeviceNotFoundException::forId($id);
        }

        // Erste nicht sperrende Abfrage nach der Sperre: sieht den aktuellen Stand.
        $current = $this->find($tenant, $id) ?? throw DeviceNotFoundException::forId($id);
        if ($current->version !== $expectedVersion) {
            throw new DeviceVersionConflictException($current);
        }

        return $current;
    }

    /**
     * Neuer Gerätetyp für updateFields(): unverändert → kein Zugriff, sonst aktiv geprüft.
     *
     * @return array{string, int} [Name für die Historie, Spaltenwert]
     */
    private function newDeviceType(Tenant $tenant, Device $current, int $typeId): array
    {
        if ($typeId === $current->deviceTypeId) {
            return [$current->deviceTypeName, $typeId];
        }

        return [$this->lockActiveDeviceType($tenant, $typeId), $typeId];
    }

    /**
     * @return string Name des Gerätetyps
     *
     * @throws InvalidDeviceValueException  unbekannt oder fremd
     * @throws DeviceRuleViolationException deaktiviert
     */
    private function lockActiveDeviceType(Tenant $tenant, int $typeId): string
    {
        [$name, $active] = $this->lockDeviceType($tenant, $typeId);
        if (!$active) {
            throw DeviceRuleViolationException::deviceTypeInactive(DeviceTypeName::fromString($name));
        }

        return $name;
    }

    /**
     * @return array{string, bool} [Name, aktiv]
     *
     * @throws InvalidDeviceValueException unbekannt oder fremd
     */
    private function lockDeviceType(Tenant $tenant, int $typeId): array
    {
        $stmt = $this->pdo->prepare('SELECT name, active FROM fe_device_types WHERE id = ? AND tenant_id = ? LOCK IN SHARE MODE');
        $stmt->execute([$typeId, $tenant->id]);
        $type = $stmt->fetch();
        if (!is_array($type)) {
            throw new InvalidDeviceValueException('Der Gerätetyp wurde nicht gefunden.');
        }

        return [(string) $type['name'], (int) $type['active'] === 1];
    }

    /**
     * Ziel-Standort (LOCK IN SHARE MODE), muss aktiv sein.
     *
     * @throws InvalidDeviceValueException  unbekannt oder fremd
     * @throws DeviceRuleViolationException gesperrt
     */
    private function lockActiveLocation(Tenant $tenant, int $locationId): void
    {
        $location = $this->lockLocation($tenant, $locationId)
            ?? throw new InvalidDeviceValueException('Der Standort wurde nicht gefunden.');
        if ((int) $location['active'] !== 1) {
            throw DeviceRuleViolationException::locationLocked(LocationName::fromString((string) $location['name']));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lockLocation(Tenant $tenant, int $locationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT name, active FROM fe_locations WHERE id = ? AND tenant_id = ? LOCK IN SHARE MODE');
        $stmt->execute([$locationId, $tenant->id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array{string, list<int|string>} SQL aus diesem Code und Parameter
     */
    private static function where(DeviceFilter $filter): array
    {
        $where = '';
        $params = [];
        if ($filter->deviceTypeId !== null) {
            $where .= ' AND d.device_type_id = ?';
            $params[] = $filter->deviceTypeId;
        }
        if ($filter->locationId !== null) {
            $where .= $filter->includeSublocations ? ' AND (d.location_id = ? OR l.parent_id = ?)' : ' AND d.location_id = ?';
            array_push($params, ...array_fill(0, $filter->includeSublocations ? 2 : 1, $filter->locationId));
        }
        if ($filter->statuses !== []) {
            $where .= ' AND d.status IN (' . implode(', ', array_fill(0, count($filter->statuses), '?')) . ')';
            array_push($params, ...array_map(static fn (DeviceStatus $s): string => $s->value, $filter->statuses));
        } elseif (!$filter->includeRetired) {
            $where .= " AND d.status <> 'retired'";
        }
        if ($filter->search !== null) {
            $where .= ' AND (d.identifier LIKE ? OR d.serial_number LIKE ? OR d.manufacturer LIKE ? OR d.model LIKE ?)';
            array_push($params, ...array_fill(0, 4, '%' . addcslashes($filter->search, '\\%_') . '%'));
        }

        return [$where, $params];
    }

    /**
     * Führt $work in einer eigenen Transaktion aus (eine bereits offene ist ein Fehler) und liefert
     * danach das Gerät mit der zurückgegebenen ID.
     *
     * @param \Closure(): int $work
     */
    private function transaction(\Closure $work, Tenant $tenant): Device
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('DeviceRepository startet eine eigene Transaktion; keine offene Transaktion erlaubt.');
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

        return $this->find($tenant, $id) ?? throw new \LogicException('Gerät nach der Änderung nicht gefunden.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Device
    {
        return new Device(
            (int) $row['id'],
            DeviceIdentifier::fromDatabase((string) $row['identifier']),
            (int) $row['device_type_id'],
            (string) $row['type_name'],
            (int) $row['location_id'],
            (string) $row['location_name'],
            $row['parent_name'] === null ? null : (string) $row['parent_name'],
            DeviceStatus::from((string) $row['status']),
            self::text($row['serial_number']),
            self::text($row['manufacturer']),
            self::text($row['model']),
            $row['procured_on'] === null ? null : DeviceDate::fromDatabase((string) $row['procured_on']),
            $row['next_inspection_due'] === null ? null : DeviceDate::fromDatabase((string) $row['next_inspection_due']),
            self::text($row['note']),
            (int) $row['version'],
            self::utc((string) $row['created_at']),
            self::utc((string) $row['updated_at']),
        );
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
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
