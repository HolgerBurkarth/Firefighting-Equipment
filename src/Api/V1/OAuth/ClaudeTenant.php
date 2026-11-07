<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\OAuth;

use FirefightingEquipment\Claude\ClaudeAvailability;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Die Stadt einer Route des Claude-Zugangs (OAuth, Metadaten): nur dort, wo es den Zugang gibt
 * (ClaudeAvailability, bis T17 nur die Test-Stadt); sonst gibt es die Adresse nicht (404).
 */
final class ClaudeTenant
{
    /**
     * @throws HttpException 404 not_found
     */
    public static function of(RequestContext $context): Tenant
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        if (!ClaudeAvailability::for($tenant)) {
            throw HttpException::notFound();
        }

        return $tenant;
    }
}
