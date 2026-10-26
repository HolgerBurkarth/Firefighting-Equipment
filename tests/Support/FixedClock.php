<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

use FirefightingEquipment\Time\Clock;

/**
 * Uhr für Tests: steht auf dem gesetzten Zeitpunkt (ISO 8601 mit Zone, z. B. 2026-06-15T22:30:00Z).
 */
final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $now)
    {
        $this->set($now);
    }

    public function set(string $now): void
    {
        $this->now = new \DateTimeImmutable($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
