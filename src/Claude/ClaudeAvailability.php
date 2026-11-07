<?php

declare(strict_types=1);

namespace FirefightingEquipment\Claude;

use FirefightingEquipment\Device\Seed\TestCityData;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Den Claude-Zugang gibt es bis zur Klärung des Datenschutzes nur in der Test-Stadt
 * (docs/MCP-KONZEPT.md, Abschnitt 8; TODO-SPAETER.md, T17). Fest im Code wie
 * McpProbeHandler::SLUG; es gibt keine Einstellung, die weitere Städte freigibt.
 */
final class ClaudeAvailability
{
    public const string SLUG = TestCityData::SLUG;

    public static function for(Tenant $tenant): bool
    {
        return $tenant->slug->value === self::SLUG;
    }
}
