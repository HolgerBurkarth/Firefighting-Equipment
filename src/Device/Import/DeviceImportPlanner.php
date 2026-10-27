<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceField;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\DeviceTypeName;
use FirefightingEquipment\Device\NewDevice;
use FirefightingEquipment\Device\ScanCode;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Time\GermanFormat;

/**
 * Prüft eine Import-Datei gegen die Datenbank der Stadt und plant jede Zeile (neu, Änderungen, keine
 * Änderung, übersprungen, Fehler). Schreibt nichts.
 *
 * Vergleiche rechnet die Datenbank, damit sie genau den UNIQUE-Indizes entsprechen: Kennung über
 * identifier_key (gleiche Formel wie die Spalte: klein, ẞ = ß, binär; also ß ≠ ss), Gerätetyp über
 * den Namen und Standort über den Pfad „HLF 20 / G1“ unter utf8mb4_unicode_ci (ohne Groß- und
 * Kleinschreibung, „Straße“ = „Strasse“). Der Pfad wird mit den Pfaden aller Standorte verglichen,
 * nie an „ / “ getrennt (Namen dürfen „/“ enthalten); passen mehrere, ist er mehrdeutig.
 *
 * Scan-Codes werden exakt verglichen (scan_code_key, binär). Ein Scan-Code darf in der Datei nur
 * einmal vorkommen und nicht schon einem anderen nicht ausgesonderten Gerät gehören, auch wenn jenes
 * ihn in derselben Datei abgibt: Scan-Codes lassen sich per Import nicht tauschen oder übertragen
 * (die UPDATEs würden zwischendurch am UNIQUE-Index scheitern).
 *
 * Mit $lock (Übernehmen, in der Transaktion von DeviceImport): Geräte FOR UPDATE samt Lücken der
 * neuen Kennungen (DeviceRepository::findByIdentifierKeys), ebenso die Scan-Codes
 * (findByScanCodes), dann alle genannten Standorte und danach alle genannten Gerätetypen LOCK IN
 * SHARE MODE; geprüft wird active aus diesen gesperrten Zeilen. Reihenfolge wie bei Einzeländerungen:
 * Gerät, Standort, Gerätetyp.
 */
final readonly class DeviceImportPlanner
{
    /** Längster möglicher Pfad: zwei Namen zu 100 Zeichen und „ / “ */
    private const int MAX_PATH_LENGTH = 203;

    public function __construct(private \PDO $pdo)
    {
    }

    public function plan(Tenant $tenant, DeviceImportFile $file, bool $lock): DeviceImportPlan
    {
        /** @var list<ImportRow> $active Zeilen, die nicht übersprungen werden */
        $active = array_values(array_filter($file->rows, static fn (ImportRow $row): bool => !$row->retired));

        $keys = $this->identifierKeys($active);
        $repository = new DeviceRepository($this->pdo);
        $devices = $repository->findByIdentifierKeys($tenant, array_values(array_unique($keys)), $lock);
        $scanCodes = self::scanCodes($active);
        $owners = $repository->findByScanCodes($tenant, array_values(array_unique($scanCodes)), $lock);
        $locations = $this->locations($tenant, self::distinct(array_map(static fn (ImportRow $r): ?string => $r->location, $active)), $lock);
        $types = $this->deviceTypes($tenant, self::distinct(array_map(static fn (ImportRow $r): ?string => $r->deviceType, $active)), $lock);

        /** @var array<string, list<int>> $linesByKey */
        $linesByKey = [];
        foreach ($keys as $line => $key) {
            $linesByKey[$key][] = $line;
        }
        /** @var array<string, list<int>> $linesByScanCode */
        $linesByScanCode = [];
        foreach ($scanCodes as $line => $scanCode) {
            $linesByScanCode[$scanCode][] = $line;
        }
        $digitIdentifiers = null;
        $digitScanCodes = null;

        $rows = [];
        $unknownTypes = [];
        $unknownLocations = [];
        foreach ($file->rows as $row) {
            if ($row->retired) {
                $rows[] = ImportRowPlan::skipped(
                    $row->line,
                    $row->identifierText,
                    'Status „ausgesondert“: Die Zeile wird übersprungen. Aussondern und Wiederaufnehmen sind nur einzeln möglich.',
                );
                continue;
            }

            $errors = $row->errors;
            $key = $keys[$row->line] ?? null;
            if ($key !== null && count($linesByKey[$key]) > 1) {
                $errors[] = sprintf(
                    'Die Kennung „%s“ kommt in der Datei mehrfach vor (Zeilen %s).',
                    $row->identifierText,
                    implode(', ', $linesByKey[$key]),
                );
            }
            $scanCode = $scanCodes[$row->line] ?? null;
            if ($scanCode !== null && count($linesByScanCode[$scanCode]) > 1) {
                $errors[] = sprintf(
                    'Der Scan-Code „%s“ kommt in der Datei mehrfach vor (Zeilen %s).',
                    ScanCode::display($scanCode),
                    implode(', ', $linesByScanCode[$scanCode]),
                );
            }

            $type = null;
            if ($row->deviceType !== null) {
                $type = $types[$row->deviceType] ?? null;
                if ($type === null) {
                    $errors[] = sprintf('Der Gerätetyp „%s“ ist nicht bekannt.', $row->deviceType);
                    $unknownTypes[$row->deviceType] = true;
                }
            }
            $location = null;
            if ($row->location !== null) {
                $candidates = $locations[$row->location] ?? [];
                if ($candidates === []) {
                    $errors[] = sprintf('Der Standort „%s“ ist nicht bekannt.', $row->location);
                    $unknownLocations[$row->location] = true;
                } elseif (count($candidates) > 1) {
                    $errors[] = sprintf('Der Standort „%s“ ist nicht eindeutig (mehrere Standorte haben diesen Pfad).', $row->location);
                } else {
                    $location = $candidates[0];
                }
            }

            $device = $key === null ? null : ($devices[$key] ?? null);
            $scanCodeWarnings = [];
            if ($scanCode !== null) {
                $owner = $owners[$scanCode] ?? null;
                if ($owner !== null && $owner->id !== $device?->id) {
                    $errors[] = sprintf(
                        'Der Scan-Code „%s“ ist bereits an das Gerät „%s“ vergeben. Per Import lassen sich Scan-Codes nicht übertragen; dazu den Scan-Code zuerst bei „%s“ entfernen.',
                        ScanCode::display($scanCode),
                        $owner->identifier->value,
                        $owner->identifier->value,
                    );
                }
                if (ImportWarnings::withoutLeadingZeros($scanCode) !== null) {
                    $digitScanCodes ??= $this->digitScanCodes($tenant);
                }
                $scanCodeWarnings = self::scanCodeWarnings($scanCode, $digitScanCodes ?? []);
            }
            if ($device === null) {
                if ($row->identifier !== null && ImportWarnings::withoutLeadingZeros($row->identifier->value) !== null) {
                    $digitIdentifiers ??= $this->digitIdentifiers($tenant);
                }
                $rows[] = $this->planNew($row, $key, $type, $location, $errors, $digitIdentifiers ?? [], $scanCodeWarnings);
            } else {
                $rows[] = $this->planUpdate($row, $device, $type, $location, $errors, $scanCodeWarnings);
            }
        }

        // Schlüssel wie „112“ macht PHP zu Zahlen.
        return new DeviceImportPlan($file->hash, $rows, array_map(strval(...), array_keys($unknownTypes)), array_map(strval(...), array_keys($unknownLocations)));
    }

    /**
     * @param array{id: int, name: string, active: bool}|null $type
     * @param array{id: int, path: string, active: bool}|null $location
     * @param list<string>                                    $errors
     * @param array<string, list<string>>                     $digitIdentifiers ohne führende Nullen => vorhandene Kennungen
     * @param list<string>                                    $scanCodeWarnings Hinweise zum Scan-Code der Zeile
     */
    private function planNew(ImportRow $row, ?string $key, ?array $type, ?array $location, array $errors, array $digitIdentifiers, array $scanCodeWarnings): ImportRowPlan
    {
        if ($row->deviceType === null) {
            $errors[] = 'Für ein neues Gerät fehlt der Gerätetyp.';
        } elseif ($type !== null && !$type['active']) {
            $errors[] = self::typeInactive($type['name']);
        }
        if ($row->location === null) {
            $errors[] = 'Für ein neues Gerät fehlt der Standort.';
        } elseif ($location !== null && !$location['active']) {
            $errors[] = self::locationLocked($location['path']);
        }
        if ($errors !== [] || $row->identifier === null || $key === null || $type === null || $location === null) {
            return ImportRowPlan::error($row->line, $row->identifierText, $errors);
        }

        $status = $row->status ?? DeviceStatus::Ready;
        $device = new NewDevice(
            $row->identifier,
            $type['id'],
            $location['id'],
            $status,
            $row->text(ImportColumn::SerialNumber),
            $row->text(ImportColumn::Manufacturer),
            $row->text(ImportColumn::Model),
            $row->date(ImportColumn::ProcuredOn),
            $row->date(ImportColumn::NextInspectionDue),
            $row->text(ImportColumn::Note),
            $row->scanCode(),
        );

        $changes = [new ImportChange(ImportColumn::Identifier, null, $row->identifier->value)];
        if ($row->scanCode() !== null) {
            $changes[] = new ImportChange(ImportColumn::ScanCode, null, $row->scanCode()->value);
        }
        array_push(
            $changes,
            new ImportChange(ImportColumn::DeviceType, null, $type['name']),
            new ImportChange(ImportColumn::Location, null, $location['path']),
            new ImportChange(ImportColumn::Status, null, $status->label()),
        );
        foreach ([ImportColumn::NextInspectionDue, ImportColumn::SerialNumber, ImportColumn::Manufacturer, ImportColumn::Model, ImportColumn::ProcuredOn, ImportColumn::Note] as $column) {
            $value = self::display($row, $column);
            if ($value !== null) {
                $changes[] = new ImportChange($column, null, $value);
            }
        }

        $warnings = [];
        $digits = ImportWarnings::withoutLeadingZeros($row->identifier->value);
        if ($digits !== null && isset($digitIdentifiers[$digits])) {
            $warnings[] = sprintf(
                'Die neue Kennung „%s“ entspricht ohne führende Nullen dem vorhandenen Gerät „%s“. Hat Excel führende Nullen entfernt? Dann die Datei mit der Spalte Kennung als Text öffnen.',
                $row->identifier->value,
                implode('“, „', $digitIdentifiers[$digits]),
            );
        }
        if (ImportWarnings::looksLikeDate($row->identifier->value)) {
            $warnings[] = self::dateWarning($row->identifier->value);
        }
        if ($row->scanCode() !== null) {
            array_push($warnings, ...$scanCodeWarnings);
        }

        return ImportRowPlan::created($row->line, $key, $device, $changes, $warnings);
    }

    /**
     * @param array{id: int, name: string, active: bool}|null $type
     * @param array{id: int, path: string, active: bool}|null $location
     * @param list<string>                                    $errors
     * @param list<string>                                    $scanCodeWarnings Hinweise zum Scan-Code der Zeile
     */
    private function planUpdate(ImportRow $row, Device $device, ?array $type, ?array $location, array $errors, array $scanCodeWarnings): ImportRowPlan
    {
        $identifier = $row->identifier ?? throw new \LogicException('Gerät ohne Kennung zugeordnet.');
        $changes = [];
        /** @var array<string, array{?string, ?string, int|string|null}> $fields */
        $fields = [];
        $warnings = [];

        if ($identifier->value !== $device->identifier->value) {
            $fields[DeviceField::Identifier->value] = [$device->identifier->value, $identifier->value, $identifier->value];
            $changes[] = new ImportChange(ImportColumn::Identifier, $device->identifier->value, $identifier->value);
            if (ImportWarnings::looksLikeDate($identifier->value)) {
                $warnings[] = self::dateWarning($identifier->value);
            }
        }

        if ($type !== null && $type['id'] !== $device->deviceTypeId) {
            if (!$type['active']) {
                $errors[] = self::typeInactive($type['name']);
            }
            $fields[DeviceField::DeviceType->value] = [$device->deviceTypeName, $type['name'], $type['id']];
            $changes[] = new ImportChange(ImportColumn::DeviceType, $device->deviceTypeName, $type['name']);
        }

        $locationId = null;
        if ($location !== null && $location['id'] !== $device->locationId) {
            if (!$location['active']) {
                $errors[] = self::locationLocked($location['path']);
            }
            $locationId = $location['id'];
            $changes[] = new ImportChange(ImportColumn::Location, $device->locationPath(), $location['path']);
        }

        $status = null;
        if ($row->status !== null && $row->status !== $device->status) {
            $status = $row->status;
            $changes[] = new ImportChange(ImportColumn::Status, $device->status->label(), $status->label());
        }

        $dueChanged = false;
        $due = $row->date(ImportColumn::NextInspectionDue);
        if ($row->has(ImportColumn::NextInspectionDue) && $due?->value !== $device->nextInspectionDue?->value) {
            $dueChanged = true;
            $changes[] = new ImportChange(ImportColumn::NextInspectionDue, self::date($device->nextInspectionDue), self::date($due));
        }

        foreach ([ImportColumn::ScanCode, ImportColumn::SerialNumber, ImportColumn::Manufacturer, ImportColumn::Model, ImportColumn::ProcuredOn, ImportColumn::Note] as $column) {
            if (!$row->has($column)) {
                continue;
            }
            $field = $column->deviceField() ?? throw new \LogicException('Kein Stammdatenfeld.');
            $new = match ($column) {
                ImportColumn::ProcuredOn => $row->date($column)?->value,
                ImportColumn::ScanCode => $row->scanCode()?->value,
                default => $row->text($column),
            };
            $old = $device->fieldText($field);
            if ($old !== $new) {
                $fields[$field->value] = [$old, $new, $new];
                $changes[] = $column === ImportColumn::ProcuredOn
                    ? new ImportChange($column, self::date($device->procuredOn), self::date($row->date($column)))
                    : new ImportChange($column, $old, $new);
                if ($column === ImportColumn::ScanCode && $new !== null) {
                    array_push($warnings, ...$scanCodeWarnings);
                }
            }
        }

        if ($errors !== []) {
            return ImportRowPlan::error($row->line, $row->identifierText, $errors, $device);
        }

        usort($changes, static fn (ImportChange $a, ImportChange $b): int => self::order($a->column) <=> self::order($b->column));

        return ImportRowPlan::updated($row->line, $row->identifierText, $device, $changes, $warnings, $fields, $dueChanged, $due, $locationId, $status);
    }

    /**
     * identifier_key je Zeile, von der Datenbank berechnet wie die Spalte in fe_devices.
     *
     * @param list<ImportRow> $rows
     *
     * @return array<int, string> Zeilennummer => Schlüssel (nur Zeilen mit gültiger Kennung)
     */
    private function identifierKeys(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (ImportRow $row): bool => $row->identifier !== null));
        if ($rows === []) {
            return [];
        }

        $values = array_map(static fn (ImportRow $row): string => $row->identifier?->value ?? '', $rows);
        $stmt = $this->pdo->prepare(
            "SELECT jt.n, REPLACE(LOWER(CONVERT(jt.v USING utf8mb4) COLLATE utf8mb4_unicode_ci), 'ẞ', 'ß') AS k"
            . " FROM JSON_TABLE(?, '$[*]' COLUMNS (n FOR ORDINALITY, v VARCHAR(30) PATH '$')) AS jt",
        );
        $stmt->execute([self::json($values)]);

        $keys = [];
        foreach ($stmt->fetchAll() as $result) {
            $keys[$rows[(int) $result['n'] - 1]->line] = (string) $result['k'];
        }

        return $keys;
    }

    /**
     * Scan-Code je Zeile (nur Zeilen mit Spalte Scan-Code und gültigem, nicht leerem Wert).
     *
     * @param list<ImportRow> $rows
     *
     * @return array<int, string> Zeilennummer => Scan-Code
     */
    private static function scanCodes(array $rows): array
    {
        $scanCodes = [];
        foreach ($rows as $row) {
            $scanCode = $row->scanCode();
            if ($scanCode !== null) {
                $scanCodes[$row->line] = $scanCode->value;
            }
        }

        return $scanCodes;
    }

    /**
     * Hinweise zu einem neuen oder geänderten Scan-Code: wissenschaftliche Schreibweise, verlorene
     * führende Nullen gegenüber einem vorhandenen Scan-Code (desselben oder eines anderen Geräts).
     *
     * @param array<string, list<array{string, string}>> $digitScanCodes ohne führende Nullen => [Scan-Code, Kennung]
     *
     * @return list<string>
     */
    private static function scanCodeWarnings(string $scanCode, array $digitScanCodes): array
    {
        $warnings = [];
        if (ImportWarnings::looksScientific($scanCode)) {
            $warnings[] = sprintf(
                'Der Scan-Code „%s“ sieht wie eine Zahl in wissenschaftlicher Schreibweise aus. Hat Excel ihn umgewandelt (dabei gehen Ziffern verloren)? Dann die Datei mit der Spalte Scan-Code als Text öffnen.',
                ScanCode::display($scanCode),
            );
        }
        $digits = ImportWarnings::withoutLeadingZeros($scanCode);
        $longer = array_values(array_filter(
            $digits === null ? [] : $digitScanCodes[$digits] ?? [],
            static fn (array $existing): bool => strlen($existing[0]) > strlen($scanCode),
        ));
        if ($longer !== []) {
            $warnings[] = sprintf(
                'Der Scan-Code „%s“ entspricht ohne führende Nullen dem vorhandenen Scan-Code %s. Hat Excel führende Nullen entfernt? Dann die Datei mit der Spalte Scan-Code als Text öffnen.',
                $scanCode,
                implode(', ', array_map(static fn (array $e): string => sprintf('„%s“ (Gerät „%s“)', $e[0], $e[1]), $longer)),
            );
        }

        return $warnings;
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, array{id: int, name: string, active: bool}> Name wie in der Datei => Gerätetyp
     */
    private function deviceTypes(Tenant $tenant, array $names, bool $lock): array
    {
        $names = array_values(array_filter($names, static fn (string $n): bool => mb_strlen($n) <= DeviceTypeName::MAX_LENGTH));
        if ($names === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT jt.n, t.id, t.name, t.active FROM JSON_TABLE(?, '$[*]' COLUMNS (n FOR ORDINALITY, v VARCHAR(100) PATH '$')) AS jt"
            . ' JOIN fe_device_types t ON t.tenant_id = ? AND t.name = CONVERT(jt.v USING utf8mb4) COLLATE utf8mb4_unicode_ci',
        );
        $stmt->execute([self::json($names), $tenant->id]);

        $types = [];
        foreach ($stmt->fetchAll() as $row) {
            $types[$names[(int) $row['n'] - 1]] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'active' => (int) $row['active'] === 1];
        }
        if ($lock) {
            $active = $this->lockActive('fe_device_types', $tenant, array_column($types, 'id'));
            foreach ($types as $name => $type) {
                $types[$name]['active'] = $active[$type['id']];
            }
        }

        return $types;
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, list<array{id: int, path: string, active: bool}>> Pfad wie in der Datei => passende Standorte
     */
    private function locations(Tenant $tenant, array $paths, bool $lock): array
    {
        $paths = array_values(array_filter($paths, static fn (string $p): bool => mb_strlen($p) <= self::MAX_PATH_LENGTH));
        if ($paths === []) {
            return [];
        }

        $path = "IF(p.id IS NULL, l.name, CONCAT(p.name, ' / ', l.name))";
        $stmt = $this->pdo->prepare(
            "SELECT jt.n, l.id, l.active, {$path} AS path"
            . " FROM JSON_TABLE(?, '$[*]' COLUMNS (n FOR ORDINALITY, v VARCHAR(250) PATH '$')) AS jt"
            . ' JOIN fe_locations l ON l.tenant_id = ?'
            . ' LEFT JOIN fe_locations p ON p.id = l.parent_id AND p.tenant_id = l.tenant_id'
            . " WHERE {$path} = CONVERT(jt.v USING utf8mb4) COLLATE utf8mb4_unicode_ci ORDER BY jt.n, l.id",
        );
        $stmt->execute([self::json($paths), $tenant->id]);

        $locations = [];
        foreach ($stmt->fetchAll() as $row) {
            $locations[$paths[(int) $row['n'] - 1]][] = ['id' => (int) $row['id'], 'path' => (string) $row['path'], 'active' => (int) $row['active'] === 1];
        }
        if ($lock) {
            $active = $this->lockActive('fe_locations', $tenant, array_merge(...array_map(
                static fn (array $candidates): array => array_column($candidates, 'id'),
                array_values($locations),
            )));
            foreach ($locations as $key => $candidates) {
                foreach ($candidates as $i => $candidate) {
                    $locations[$key][$i]['active'] = $active[$candidate['id']];
                }
            }
        }

        return $locations;
    }

    /**
     * Liest active der Zeilen mit LOCK IN SHARE MODE (aktueller Stand, gesperrt bis zum Ende der
     * Transaktion).
     *
     * @param 'fe_device_types'|'fe_locations' $table
     * @param list<int>                        $ids
     *
     * @return array<int, bool>
     */
    private function lockActive(string $table, Tenant $tenant, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        sort($ids);
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT id, active FROM %s WHERE tenant_id = ? AND id IN (%s) ORDER BY id LOCK IN SHARE MODE',
            $table,
            implode(', ', array_fill(0, count($ids), '?')),
        ));
        $stmt->execute([$tenant->id, ...$ids]);

        $active = [];
        foreach ($stmt->fetchAll() as $row) {
            $active[(int) $row['id']] = (int) $row['active'] === 1;
        }

        return $active;
    }

    /**
     * Kennungen nicht ausgesonderter Geräte, die nur aus Ziffern bestehen (für die Warnung zu
     * führenden Nullen).
     *
     * @return array<string, list<string>> ohne führende Nullen => Kennungen
     */
    private function digitIdentifiers(Tenant $tenant): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT identifier FROM fe_devices WHERE tenant_id = ? AND status <> 'retired'"
            . " AND identifier COLLATE utf8mb4_bin REGEXP '^[0-9]+\\\\z' ORDER BY identifier",
        );
        $stmt->execute([$tenant->id]);

        $identifiers = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $identifier) {
            $identifiers[ImportWarnings::withoutLeadingZeros((string) $identifier) ?? ''][] = (string) $identifier;
        }

        return $identifiers;
    }

    /**
     * Scan-Codes nicht ausgesonderter Geräte, die nur aus Ziffern bestehen (für die Warnung zu
     * führenden Nullen).
     *
     * @return array<string, list<array{string, string}>> ohne führende Nullen => [Scan-Code, Kennung]
     */
    private function digitScanCodes(Tenant $tenant): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT scan_code, identifier FROM fe_devices WHERE tenant_id = ? AND scan_code_key IS NOT NULL'
            . " AND scan_code_key REGEXP '^[0-9]+\\\\z' ORDER BY scan_code_key",
        );
        $stmt->execute([$tenant->id]);

        $scanCodes = [];
        foreach ($stmt->fetchAll() as $row) {
            $scanCodes[ImportWarnings::withoutLeadingZeros((string) $row['scan_code']) ?? ''][] = [(string) $row['scan_code'], (string) $row['identifier']];
        }

        return $scanCodes;
    }

    private static function display(ImportRow $row, ImportColumn $column): ?string
    {
        return match ($column) {
            ImportColumn::NextInspectionDue, ImportColumn::ProcuredOn => self::date($row->date($column)),
            default => $row->text($column),
        };
    }

    private static function date(?DeviceDate $date): ?string
    {
        return $date === null ? null : GermanFormat::date($date->value);
    }

    private static function order(ImportColumn $column): int
    {
        return (int) array_search($column, ImportColumn::cases(), true);
    }

    private static function typeInactive(string $name): string
    {
        return sprintf('Der Gerätetyp „%s“ ist deaktiviert und kann keinem Gerät zugeordnet werden.', $name);
    }

    private static function locationLocked(string $path): string
    {
        return sprintf('Der Standort „%s“ ist gesperrt und kann keine Geräte aufnehmen.', $path);
    }

    private static function dateWarning(string $identifier): string
    {
        return sprintf(
            'Die Kennung „%s“ sieht wie ein Datum aus. Hat Excel sie umgewandelt? Dann die Datei mit der Spalte Kennung als Text öffnen.',
            $identifier,
        );
    }

    /**
     * @param list<string|null> $values
     *
     * @return list<string>
     */
    private static function distinct(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (?string $v): bool => $v !== null)));
    }

    /**
     * @param list<string> $values
     */
    private static function json(array $values): string
    {
        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
