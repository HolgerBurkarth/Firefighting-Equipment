<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Wer eine Rolle nutzt: Konten (ohne gelöschte) und offene Einladungscodes (nutzbar: nicht
 * widerrufen, nicht aufgebraucht, nicht abgelaufen).
 */
final readonly class RoleUsage
{
    public function __construct(public int $accounts, public int $openInvitationCodes)
    {
    }

    public function isUnused(): bool
    {
        return $this->accounts === 0 && $this->openInvitationCodes === 0;
    }
}
