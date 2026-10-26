<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Zahlen für die Startseite, ohne ausgesonderte Geräte.
 */
final readonly class DeviceSummary
{
    /**
     * @param array<string, int> $byStatus Anzahl je Statuswert (ready, maintenance, defective; immer
     *                                     alle drei, auch 0)
     */
    public function __construct(
        public InspectionWindow $window,
        public array $byStatus,
        public int $overdue,
        public int $dueSoon,
        public int $withoutDate,
    ) {
    }

    public function total(): int
    {
        return array_sum($this->byStatus);
    }
}
