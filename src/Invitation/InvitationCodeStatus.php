<?php

declare(strict_types=1);

namespace FirefightingEquipment\Invitation;

/**
 * Zustand eines Einladungscodes; nicht gespeichert, sondern beim Aufruf berechnet.
 * Vorrang: widerrufen, aufgebraucht, abgelaufen, sonst aktiv.
 */
enum InvitationCodeStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Exhausted = 'exhausted';
    case Revoked = 'revoked';

    public static function of(bool $revoked, int $useCount, int $maxUses, bool $expired): self
    {
        return match (true) {
            $revoked => self::Revoked,
            $useCount >= $maxUses => self::Exhausted,
            $expired => self::Expired,
            default => self::Active,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'aktiv',
            self::Expired => 'abgelaufen',
            self::Exhausted => 'aufgebraucht',
            self::Revoked => 'widerrufen',
        };
    }
}
