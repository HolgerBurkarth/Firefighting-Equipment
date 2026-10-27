<?php

declare(strict_types=1);

namespace FirefightingEquipment\Offline;

use FirefightingEquipment\Device\InvalidDeviceValueException;

/**
 * Aktionszeit einer Buchung auf dem Handy (occurred_at): genau JJJJ-MM-TTTHH:MM:SS mit optional
 * 1 bis 6 Nachkommastellen und Z (UTC). Gespeichert wird auf Sekunden gekürzt (DATETIME).
 */
final readonly class ActionTime
{
    private const string PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,6})?Z\z/';

    private function __construct(public \DateTimeImmutable $value)
    {
    }

    /**
     * @throws InvalidDeviceValueException
     */
    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59
            || (int) $m[1] < 2000) {
            throw new InvalidDeviceValueException('Das Feld "occurred_at" muss ein Zeitpunkt in UTC sein, z. B. 2026-10-27T14:05:00Z.');
        }

        return new self(new \DateTimeImmutable(sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]), new \DateTimeZone('UTC')));
    }

    /** Wert für eine DATETIME-Spalte (UTC). */
    public function sql(): string
    {
        return $this->value->format('Y-m-d H:i:s');
    }

    /** ISO 8601 in UTC mit Z, z. B. 2026-10-27T14:05:00Z. */
    public static function format(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
