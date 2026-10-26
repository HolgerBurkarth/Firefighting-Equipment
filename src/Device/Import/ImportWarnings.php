<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

/**
 * Erkennt Kennungen, die Excel beim Öffnen einer CSV-Datei vermutlich verändert hat: führende
 * Nullen entfernt („007“ → „7“) oder in ein Datum umgewandelt („1-3“ → „01.03.2026“, „01. Mrz“).
 * Nur Hinweise in der Vorschau, sie blockieren den Import nicht.
 */
final class ImportWarnings
{
    /** Deutsche und englische Monatsnamen und ihre Abkürzungen, wie Excel sie schreibt */
    private const array MONTHS = [
        'jan', 'januar', 'january', 'feb', 'februar', 'february', 'mär', 'mrz', 'märz', 'mar', 'march',
        'apr', 'april', 'mai', 'may', 'jun', 'juni', 'june', 'jul', 'juli', 'july', 'aug', 'august',
        'sep', 'sept', 'september', 'okt', 'oktober', 'oct', 'october', 'nov', 'november', 'dez',
        'dezember', 'dec', 'december',
    ];

    private function __construct()
    {
    }

    /**
     * Ziffernfolge ohne führende Nullen („007“ → „7“, „000“ → „0“); null, wenn die Kennung nicht nur
     * aus Ziffern besteht.
     */
    public static function withoutLeadingZeros(string $identifier): ?string
    {
        if (preg_match('/^[0-9]+\z/', $identifier) !== 1) {
            return null;
        }
        $trimmed = ltrim($identifier, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    public static function looksLikeDate(string $identifier): bool
    {
        if (preg_match('~^(\d{1,2}\.\d{1,2}\.(\d{2}|\d{4})|\d{4}-\d{1,2}-\d{1,2}|\d{1,2}/\d{1,2}/(\d{2}|\d{4}))\z~', $identifier) === 1) {
            return true;
        }
        // „01. Mrz“, „1-Mar“, „01. März 2026“, „Mrz 26“, „Mar-26“
        if (preg_match('~^\d{1,2}[-. ]+(\p{L}{3,})\.?(?:[-. ]+\d{2,4})?\z~u', $identifier, $m) === 1
            || preg_match('~^(\p{L}{3,})\.?[-. ]+\d{2,4}\z~u', $identifier, $m) === 1) {
            return in_array(mb_strtolower($m[1]), self::MONTHS, true);
        }

        return false;
    }
}
