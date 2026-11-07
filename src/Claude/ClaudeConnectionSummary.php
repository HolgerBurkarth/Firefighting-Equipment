<?php

declare(strict_types=1);

namespace FirefightingEquipment\Claude;

/**
 * Claude-Verbindungen eines Kontos: Anzahl und letzte Nutzung (größtes last_seen_at, UTC).
 */
final readonly class ClaudeConnectionSummary
{
    public function __construct(
        public int $count,
        public \DateTimeImmutable $lastUsedAt,
    ) {
    }
}
