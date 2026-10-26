<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Merkt sich die IDs der Ereignisse, die DeviceRepository in seiner letzten Transaktion geschrieben
 * hat (für die Benachrichtigung „Log“ nach dem Commit). Jede Transaktion beginnt mit einer leeren
 * Liste; nach einem Rollback ist sie leer. Nur der ungepufferte DeviceEventWriter meldet IDs (der
 * CSV-Import benachrichtigt über seinen Plan).
 */
final class DeviceEventRecorder
{
    /** @var list<int> */
    private array $ids = [];

    public function add(int $id): void
    {
        $this->ids[] = $id;
    }

    public function reset(): void
    {
        $this->ids = [];
    }

    /**
     * @return list<int> in der Reihenfolge des Schreibens
     */
    public function ids(): array
    {
        return $this->ids;
    }
}
