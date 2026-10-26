<?php

declare(strict_types=1);

namespace FirefightingEquipment\Time;

/**
 * Kalendertage in deutscher Zeit. Prüfdaten sind Kalendertage ohne Uhrzeit; „heute“ richtet sich
 * daher nach Europe/Berlin (MEZ/MESZ), nicht nach UTC. Rechnen mit Tagen geschieht ohne Zeitzone,
 * damit die Zeitumstellung keinen Tag verschiebt.
 */
final class LocalDate
{
    public const string TIMEZONE = 'Europe/Berlin';

    /**
     * @return string JJJJ-MM-TT
     */
    public static function today(Clock $clock): string
    {
        return $clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');
    }

    /**
     * @param string $date JJJJ-MM-TT
     *
     * @return string JJJJ-MM-TT
     */
    public static function addDays(string $date, int $days): string
    {
        return self::parse($date)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * Tage von $from bis $to (negativ, wenn $to vor $from liegt).
     */
    public static function daysBetween(string $from, string $to): int
    {
        $diff = self::parse($from)->diff(self::parse($to));

        return $diff->invert === 1 ? -(int) $diff->days : (int) $diff->days;
    }

    private static function parse(string $date): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException(sprintf('Ungültiges Datum: %s', $date));
        }

        return $parsed;
    }
}
