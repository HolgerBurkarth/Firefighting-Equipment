<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Aufgaben von Standorten, die der Super-Admin je Stadt freischaltet (fe_tenant_location_tasks).
 * Ohne Zeile ist eine Aufgabe nicht freigeschaltet und hat den Standardnamen. Eine Aufgabe lässt sich
 * nur abschalten, wenn kein Standort sie mehr trägt (409 mit Anzahl).
 */
final readonly class LocationTaskRepository
{
    public const int LABEL_MAX_LENGTH = 50;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Alle Aufgaben des Katalogs in fester Reihenfolge.
     *
     * @return list<array{task: LocationTask, label: string, default_label: string, enabled: bool, locations: int}>
     */
    public function list(Tenant $tenant): array
    {
        $stmt = $this->pdo->prepare('SELECT task, label, enabled FROM fe_tenant_location_tasks WHERE tenant_id = ?');
        $stmt->execute([$tenant->id]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(string) $row['task']] = $row;
        }
        $usage = $this->pdo->prepare('SELECT task, COUNT(*) AS n FROM fe_locations WHERE tenant_id = ? AND task IS NOT NULL GROUP BY task');
        $usage->execute([$tenant->id]);
        $used = [];
        foreach ($usage->fetchAll() as $row) {
            $used[(string) $row['task']] = (int) $row['n'];
        }

        $result = [];
        foreach (LocationTask::cases() as $task) {
            $row = $rows[$task->value] ?? null;
            $result[] = [
                'task' => $task,
                'label' => $row !== null && $row['label'] !== null ? (string) $row['label'] : $task->label(),
                'default_label' => $task->label(),
                'enabled' => $row !== null && (int) $row['enabled'] === 1,
                'locations' => $used[$task->value] ?? 0,
            ];
        }

        return $result;
    }

    public function isEnabled(Tenant $tenant, LocationTask $task): bool
    {
        $stmt = $this->pdo->prepare('SELECT enabled FROM fe_tenant_location_tasks WHERE tenant_id = ? AND task = ?');
        $stmt->execute([$tenant->id, $task->value]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * Speichert die Freischaltung und den Anzeigenamen einer Aufgabe ($label null oder leer =
     * Standardname).
     *
     * @throws InvalidDeviceValueException  Name zu lang oder mit Steuerzeichen
     * @throws DeviceRuleViolationException Abschalten, solange Standorte die Aufgabe tragen
     */
    public function save(Tenant $tenant, LocationTask $task, bool $enabled, ?string $label): void
    {
        $label = $label === null ? null : trim($label);
        if ($label === '') {
            $label = null;
        }
        if ($label !== null) {
            if (mb_strlen($label) > self::LABEL_MAX_LENGTH) {
                throw new InvalidDeviceValueException(sprintf('Der Name der Aufgabe darf höchstens %d Zeichen haben.', self::LABEL_MAX_LENGTH));
            }
            if (preg_match('/\p{Cc}/u', $label) === 1) {
                throw new InvalidDeviceValueException('Der Name der Aufgabe darf keine Steuerzeichen enthalten.');
            }
        }

        if ($this->pdo->inTransaction()) {
            throw new \LogicException('LocationTaskRepository startet eine eigene Transaktion; keine offene Transaktion erlaubt.');
        }
        $this->pdo->beginTransaction();
        try {
            if (!$enabled) {
                $stmt = $this->pdo->prepare('SELECT id FROM fe_locations WHERE tenant_id = ? AND task = ? FOR UPDATE');
                $stmt->execute([$tenant->id, $task->value]);
                $locations = count($stmt->fetchAll());
                if ($locations > 0) {
                    throw DeviceRuleViolationException::because(
                        sprintf('Die Aufgabe „%s“ wird noch von %d Standort(en) verwendet und kann nicht abgeschaltet werden.', $task->label(), $locations),
                        ['locations' => $locations],
                    );
                }
            }
            $this->pdo->prepare(
                'INSERT INTO fe_tenant_location_tasks (tenant_id, task, label, enabled, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
                . ' ON DUPLICATE KEY UPDATE label = VALUES(label), enabled = VALUES(enabled), updated_at = UTC_TIMESTAMP()',
            )->execute([$tenant->id, $task->value, $label, (int) $enabled]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
