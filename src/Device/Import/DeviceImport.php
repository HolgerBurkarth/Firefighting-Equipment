<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

use FirefightingEquipment\Device\DeviceEventWriter;
use FirefightingEquipment\Device\DeviceField;
use FirefightingEquipment\Tenant\Tenant;

/**
 * CSV-Import von Geräten in zwei Stufen:
 *
 * 1. preview(): Datei prüfen, nichts speichern; liefert den Plan mit Kennzeichen (nur ohne Fehler).
 * 2. apply(): in EINER Transaktion dieselbe Datei erneut prüfen, diesmal mit Sperren (siehe
 *    DeviceImportPlanner), und nur schreiben, wenn sie fehlerfrei ist und das Kennzeichen gleich ist
 *    (sonst ImportOutdatedException, nichts geschrieben). Alles oder nichts.
 *
 * Geschrieben wird wie bei der normalen Bearbeitung: neues Gerät mit Version 1 und Ereignis
 * imported; bestehendes Gerät mit einem UPDATE (Version + 1) und den Ereignissen field_changed (je
 * Feld, Reihenfolge von DeviceField), due_changed und state_changed (Standort und Status in einem
 * Eintrag), alle mit dem gemeinsamen Kommentar. Neue Geräte und Ereignisse werden gesammelt in
 * INSERTs mit mehreren Zeilen geschrieben (Laufzeit auf dem Server).
 */
final readonly class DeviceImport
{
    private const int ER_DUP_ENTRY = 1062;
    private const int ER_LOCK_DEADLOCK = 1213;

    /** Geräte je INSERT */
    private const int INSERT_ROWS = 500;

    /** Spalte in fe_devices je Stammdatenfeld */
    private const array COLUMNS = [
        'identifier' => 'identifier',
        'scan_code' => 'scan_code',
        'device_type' => 'device_type_id',
        'serial_number' => 'serial_number',
        'manufacturer' => 'manufacturer',
        'model' => 'model',
        'procured_on' => 'procured_on',
        'note' => 'note',
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    public function preview(Tenant $tenant, DeviceImportFile $file): DeviceImportPlan
    {
        return new DeviceImportPlanner($this->pdo)->plan($tenant, $file, false);
    }

    /**
     * @param int    $userId Konto der Stadt, das importiert
     * @param string $token  Kennzeichen aus der Vorschau
     *
     * @throws ImportOutdatedException
     */
    public function apply(Tenant $tenant, int $userId, DeviceImportFile $file, string $token, ?string $comment): DeviceImportPlan
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('DeviceImport startet eine eigene Transaktion; keine offene Transaktion erlaubt.');
        }

        $this->pdo->beginTransaction();
        try {
            $plan = new DeviceImportPlanner($this->pdo)->plan($tenant, $file, true);
            if ($plan->token === null || !hash_equals($plan->token, $token)) {
                throw new ImportOutdatedException($plan);
            }
            $this->write($tenant, $userId, $plan, $comment);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof \PDOException && in_array($e->errorInfo[1] ?? null, [self::ER_DUP_ENTRY, self::ER_LOCK_DEADLOCK], true)) {
                // Gleichzeitig vergebene Kennung oder Verklemmung mit einer anderen Änderung.
                throw new ImportOutdatedException(null);
            }
            throw $e;
        }

        return $plan;
    }

    private function write(Tenant $tenant, int $userId, DeviceImportPlan $plan, ?string $comment): void
    {
        $events = new DeviceEventWriter($this->pdo, buffered: true);

        $created = array_values(array_filter($plan->rows, static fn (ImportRowPlan $row): bool => $row->result === ImportResult::New));
        $ids = $this->insertDevices($tenant, $created);
        foreach ($created as $row) {
            $device = $row->newDevice ?? throw new \LogicException('Neues Gerät fehlt.');
            $events->imported($tenant, $ids[$row->key ?? ''], $userId, $device->locationId, $device->status, $comment);
        }

        $statements = [];
        foreach ($plan->rows as $row) {
            if ($row->result !== ImportResult::Changed) {
                continue;
            }
            $device = $row->device ?? throw new \LogicException('Gerät fehlt.');

            $set = [];
            $params = [];
            foreach ($row->fieldChanges as $field => [, , $value]) {
                $set[] = self::COLUMNS[$field] . ' = ?';
                $params[] = $value;
            }
            if ($row->dueChanged) {
                $set[] = 'next_inspection_due = ?';
                $params[] = $row->due?->value;
            }
            if ($row->locationId !== null) {
                $set[] = 'location_id = ?';
                $params[] = $row->locationId;
            }
            if ($row->status !== null) {
                $set[] = 'status = ?';
                $params[] = $row->status->value;
            }
            if ($row->locationId !== null || $row->status !== null) {
                // vor version = version + 1 (siehe DeviceRepository::applyState)
                $set[] = 'state_changed_at = UTC_TIMESTAMP(), state_version = version + 1';
            }
            $sql = 'UPDATE fe_devices SET ' . implode(', ', $set) . ', version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ?';
            ($statements[$sql] ??= $this->pdo->prepare($sql))->execute([...$params, $device->id, $tenant->id]);

            foreach (DeviceField::cases() as $field) {
                if (isset($row->fieldChanges[$field->value])) {
                    [$old, $new] = $row->fieldChanges[$field->value];
                    $events->fieldChanged($tenant, $device->id, $userId, $field, $old, $new, $comment);
                }
            }
            if ($row->dueChanged) {
                $events->dueChanged($tenant, $device->id, $userId, $device->nextInspectionDue, $row->due, $comment);
            }
            if ($row->locationId !== null || $row->status !== null) {
                $events->stateChanged(
                    $tenant,
                    $device->id,
                    $userId,
                    $row->locationId === null ? null : $device->locationId,
                    $row->locationId,
                    $row->status === null ? null : $device->status,
                    $row->status,
                    $comment,
                );
            }
        }

        $events->flush();
    }

    /**
     * Legt die neuen Geräte an (Version 1) und liefert ihre IDs.
     *
     * @param list<ImportRowPlan> $rows
     *
     * @return array<string, int> identifier_key => ID
     */
    private function insertDevices(Tenant $tenant, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $placeholders = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())';
        foreach (array_chunk($rows, self::INSERT_ROWS) as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                $device = $row->newDevice ?? throw new \LogicException('Neues Gerät fehlt.');
                array_push(
                    $params,
                    $tenant->id,
                    $device->identifier->value,
                    $device->scanCode?->value,
                    $device->deviceTypeId,
                    $device->locationId,
                    $device->status->value,
                    $device->serialNumber,
                    $device->manufacturer,
                    $device->model,
                    $device->procuredOn?->value,
                    $device->nextInspectionDue?->value,
                    $device->note,
                );
            }
            $this->pdo->prepare(
                'INSERT INTO fe_devices (tenant_id, identifier, scan_code, device_type_id, location_id, status, serial_number,'
                . ' manufacturer, model, procured_on, next_inspection_due, note, version, state_changed_at, state_version,'
                . ' created_at, updated_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), $placeholders)),
            )->execute($params);
        }

        // Die IDs über die Kennung; mehrzeilige INSERTs garantieren keine lückenlosen IDs.
        $keys = array_map(static fn (ImportRowPlan $row): string => $row->key ?? '', $rows);
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT identifier_key, id FROM fe_devices WHERE tenant_id = ? AND identifier_key IN (%s)',
            implode(', ', array_fill(0, count($keys), '?')),
        ));
        $stmt->execute([$tenant->id, ...$keys]);

        $ids = [];
        foreach ($stmt->fetchAll() as $result) {
            $ids[(string) $result['identifier_key']] = (int) $result['id'];
        }

        return $ids;
    }
}
