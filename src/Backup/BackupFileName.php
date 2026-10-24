<?php

declare(strict_types=1);

namespace FirefightingEquipment\Backup;

/**
 * Dateinamen der Datenbanksicherungen: ff-db-JJJJMMTT-HHMMSSZ[-zusatz].sql.gz (Zeit in UTC).
 * Die Namen sind nach Zeit sortierbar.
 */
final readonly class BackupFileName
{
    public const string PATTERN = '/^ff-db-\d{8}-\d{6}Z(?:-[a-z0-9]+(?:-[a-z0-9]+)*)?\.sql\.gz$/D';
    public const int SUFFIX_MAX_LENGTH = 60;

    /**
     * @throws \InvalidArgumentException bei ungültigem Zusatz
     */
    public static function create(\DateTimeImmutable $time, ?string $suffix = null): string
    {
        $name = 'ff-db-' . $time->setTimezone(new \DateTimeZone('UTC'))->format('Ymd-His') . 'Z';
        if ($suffix !== null) {
            self::assertValidSuffix($suffix);
            $name .= '-' . $suffix;
        }

        return $name . '.sql.gz';
    }

    /**
     * Zusatz: a-z, 0-9 und Bindestrich, Bindestrich nicht am Anfang/Ende und nicht doppelt.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValidSuffix(string $suffix): void
    {
        if (strlen($suffix) > self::SUFFIX_MAX_LENGTH || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $suffix) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Ungültiger Zusatz "%s": erlaubt sind a-z, 0-9 und Bindestrich (nicht am Anfang/Ende, nicht doppelt),'
                . ' höchstens %d Zeichen.',
                $suffix,
                self::SUFFIX_MAX_LENGTH,
            ));
        }
    }

    public static function matches(string $filename): bool
    {
        return preg_match(self::PATTERN, $filename) === 1;
    }
}
