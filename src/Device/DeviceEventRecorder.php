<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Merkt sich die IDs der Ereignisse, die DeviceRepository in seiner letzten Transaktion geschrieben
 * hat (für die Benachrichtigung „Log“ nach dem Commit). Jede Transaktion beginnt mit einer leeren
 * Liste; nach einem Rollback ist sie leer. Nur der ungepufferte DeviceEventWriter meldet IDs (der
 * CSV-Import benachrichtigt über seinen Plan). Dazu der Stand des Geräts vor der Änderung (before(),
 * für den Datenfilter der Empfänger: sichtbar vorher oder nachher).
 */
final class DeviceEventRecorder
{
    /** @var list<int> */
    private array $ids = [];

    private ?Device $before = null;

    public function add(int $id): void
    {
        $this->ids[] = $id;
    }

    public function reset(): void
    {
        $this->ids = [];
        $this->before = null;
    }

    /** Merkt sich den Stand vor der Änderung (gesperrt gelesen) bzw. liefert ihn ohne Argument. */
    public function before(?Device $device = null): ?Device
    {
        if ($device !== null) {
            $this->before = $device;
        }

        return $this->before;
    }

    /**
     * @return list<int> in der Reihenfolge des Schreibens
     */
    public function ids(): array
    {
        return $this->ids;
    }
}
