<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

/**
 * Eine Stadt mit der Zahl ihrer Konten (Übersicht des Super-Admins).
 */
final readonly class TenantSummary
{
    public function __construct(
        public Tenant $tenant,
        /** Konten mit Status active, nicht gesperrt (alle Rollen). */
        public int $activeUsers,
        /** davon Admins */
        public int $activeAdmins,
        /** Konten mit Status invited (Einladung als Admin noch nicht angenommen). */
        public int $invitedAdmins,
    ) {
    }
}
