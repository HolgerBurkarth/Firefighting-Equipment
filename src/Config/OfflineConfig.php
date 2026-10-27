<?php

declare(strict_types=1);

namespace FirefightingEquipment\Config;

/**
 * Optionale Gruppe 'offline' der Konfiguration: Zeitlimits für Buchungen ohne Netz. Standardwerte =
 * Produktion.
 */
final readonly class OfflineConfig
{
    public const int DEFAULT_MAX_AGE_DAYS = 7;
    public const int DEFAULT_QUEUE_MAX_AGE_DAYS = 7;
    public const int MIN_DAYS = 1;
    public const int MAX_DAYS = 365;

    public function __construct(
        /** So lange darf das Handy nach der letzten Synchronisierung offline buchen (prüft nur das Handy). */
        public int $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS,
        /** Ab diesem Alter einer Buchung fragt das Handy nach (sonst 409 stale_action). */
        public int $queueMaxAgeDays = self::DEFAULT_QUEUE_MAX_AGE_DAYS,
    ) {
        foreach ([$maxAgeDays, $queueMaxAgeDays] as $days) {
            if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
                throw new \InvalidArgumentException('Tage außerhalb des erlaubten Bereichs.');
            }
        }
    }
}
