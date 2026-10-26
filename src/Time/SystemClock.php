<?php

declare(strict_types=1);

namespace FirefightingEquipment\Time;

final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
