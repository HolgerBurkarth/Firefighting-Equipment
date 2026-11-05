<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Anzahl Konten einer Stadt mit derselben Kontoart (fe_users.role) und demselben Status.
 */
final readonly class RoleAssignmentGroup
{
    public function __construct(
        public string $tenantSlug,
        public string $accountRole,
        public string $status,
        public int $accounts,
    ) {
    }
}
