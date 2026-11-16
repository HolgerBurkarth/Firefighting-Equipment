<?php

declare(strict_types=1);

namespace FirefightingEquipment\Claude;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Ob es den Claude-Zugang in einer Stadt gibt: Einstellung je Stadt (fe_tenants.claude_enabled,
 * Migration 0066), die nur der Super-Admin schaltet (T17, Entscheidung Holger 16.11.2026; Standard
 * aus, die Test-Stadt per Migration an). Verbieten beendet alle Verbindungen der Stadt
 * (TenantRepository::setClaudeEnabled).
 */
final class ClaudeAvailability
{
    public static function for(Tenant $tenant): bool
    {
        return $tenant->claudeEnabled;
    }
}
