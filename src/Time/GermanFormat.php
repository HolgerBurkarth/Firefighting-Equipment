<?php

declare(strict_types=1);

namespace FirefightingEquipment\Time;

/**
 * Deutsche Schreibweise für Dateien und Anzeigen: Datum TT.MM.JJJJ, Zeitpunkt in deutscher Zeit
 * (Europe/Berlin) als TT.MM.JJJJ HH:MM.
 */
final class GermanFormat
{
    /**
     * @param string $date JJJJ-MM-TT
     */
    public static function date(string $date): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $m) !== 1) {
            throw new \InvalidArgumentException(sprintf('Ungültiges Datum: %s', $date));
        }

        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }

    public static function dateTime(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone(LocalDate::TIMEZONE))->format('d.m.Y H:i');
    }
}
