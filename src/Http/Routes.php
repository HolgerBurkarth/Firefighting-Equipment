<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Api\V1\HealthHandler;
use FirefightingEquipment\Api\V1\TenantHandler;

/**
 * Alle Routen der Anwendung. Neue Endpunkte werden hier eingetragen.
 */
final class Routes
{
    /** Routen unter /firefighting/service/ (Super-Admin, keine Stadt). */
    public static function service(): Router
    {
        return new Router()
            ->add('GET', 'api/v1/health', new HealthHandler());
    }

    /** Routen unter /firefighting/<kürzel>/ (nur aktive Städte). */
    public static function tenant(): Router
    {
        return new Router()
            ->add('GET', 'api/v1/health', new HealthHandler())
            ->add('GET', 'api/v1/tenant', new TenantHandler());
    }
}
