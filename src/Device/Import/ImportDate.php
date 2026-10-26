<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\InvalidDeviceValueException;

/**
 * Datum in einer Import-Datei: TT.MM.JJJJ (wie im Export), auch ohne führende Nullen (T.M.JJJJ,
 * Excel kürzt manchmal), oder JJJJ-MM-TT. Zweistellige Jahre werden abgelehnt. Bereich wie
 * DeviceDate (1950-01-01 bis 2099-12-31).
 */
final class ImportDate
{
    private function __construct()
    {
    }

    /**
     * @param string $value  bereits getrimmt, nicht leer
     * @param string $subject z. B. „Das Beschaffungsdatum“
     *
     * @throws InvalidDeviceValueException
     */
    public static function parse(string $value, string $subject): DeviceDate
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})\z/', $value, $m) === 1) {
            [$year, $month, $day] = [$m[3], $m[2], $m[1]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) === 1) {
            [$year, $month, $day] = [$m[1], $m[2], $m[3]];
        } else {
            throw new InvalidDeviceValueException(sprintf('%s „%s“ muss ein Datum im Format TT.MM.JJJJ (oder JJJJ-MM-TT) sein.', $subject, $value));
        }
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            throw new InvalidDeviceValueException(sprintf('%s „%s“ ist kein gültiger Kalendertag.', $subject, $value));
        }

        $iso = sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
        if ($iso < DeviceDate::MIN || $iso > DeviceDate::MAX) {
            throw new InvalidDeviceValueException(sprintf('%s „%s“ muss zwischen 01.01.1950 und 31.12.2099 liegen.', $subject, $value));
        }

        return DeviceDate::fromString($iso, $subject);
    }
}
