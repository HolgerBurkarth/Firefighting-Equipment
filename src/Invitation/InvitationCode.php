<?php

declare(strict_types=1);

namespace FirefightingEquipment\Invitation;

/**
 * Gespeicherter Einladungscode (ohne Klartext, der wird nur beim Anlegen einmal geliefert).
 */
final readonly class InvitationCode
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public ?InvitationCodeLabel $label,
        public int $maxUses,
        public int $useCount,
        /** UTC */
        public \DateTimeImmutable $expiresAt,
        /** UTC; null = nicht widerrufen */
        public ?\DateTimeImmutable $revokedAt,
        /** Konto des Admins, der den Code angelegt hat */
        public int $createdBy,
        /** UTC */
        public \DateTimeImmutable $createdAt,
        /** Berechnet beim Laden (Zeitvergleich in der Datenbank) */
        public InvitationCodeStatus $status,
    ) {
    }
}
