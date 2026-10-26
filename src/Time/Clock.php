<?php

declare(strict_types=1);

namespace FirefightingEquipment\Time;

/**
 * Aktuelle Zeit. In der Anwendung SystemClock; Tests übergeben eine feste Uhr, damit Fälligkeiten
 * (z. B. Mitternacht deutscher Zeit, Zeitumstellung) gezielt prüfbar sind.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
