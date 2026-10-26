<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\LocalDate;

/**
 * Zeitraum der Prüffristen: „heute“ nach deutscher Zeit (LocalDate) und das Ende des Abschnitts
 * „bald fällig“ (heute + $days, einschließlich). Überfällig ist ein Prüfdatum vor heute, bald
 * fällig eines von heute bis $until.
 */
final readonly class InspectionWindow
{
    public const int DEFAULT_DAYS = 30;
    public const int MIN_DAYS = 1;
    public const int MAX_DAYS = 365;

    private function __construct(
        /** JJJJ-MM-TT */
        public string $today,
        public int $days,
        /** JJJJ-MM-TT, letzter Tag von „bald fällig“ */
        public string $until,
    ) {
    }

    public static function from(Clock $clock, int $days = self::DEFAULT_DAYS): self
    {
        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            throw new \InvalidArgumentException(sprintf('days muss zwischen %d und %d liegen.', self::MIN_DAYS, self::MAX_DAYS));
        }
        $today = LocalDate::today($clock);

        return new self($today, $days, LocalDate::addDays($today, $days));
    }

    /**
     * Tage bis zur Fälligkeit: 0 = heute fällig, negativ = überfällig.
     */
    public function daysUntil(DeviceDate $due): int
    {
        return LocalDate::daysBetween($this->today, $due->value);
    }
}
