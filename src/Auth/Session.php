<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Eine Sitzung aus fe_sessions (ohne Token-Hash).
 */
final readonly class Session
{
    public function __construct(
        public int $id,
        public int $userId,
        /** UTC */
        public \DateTimeImmutable $createdAt,
        /** UTC */
        public \DateTimeImmutable $lastSeenAt,
        /** UTC; Höchstdauer */
        public \DateTimeImmutable $expiresAt,
        /** Grobe Gerätebezeichnung, z. B. "Chrome auf Android" */
        public ?string $deviceLabel,
    ) {
    }
}
