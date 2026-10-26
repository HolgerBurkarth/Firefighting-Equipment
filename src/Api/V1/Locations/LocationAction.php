<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Locations;

/**
 * Aktion auf Standorte (LocationActionHandler). Verschieben und Art ändern gibt es in Version 1 nicht.
 */
enum LocationAction
{
    /** GET api/v1/locations/{id} (angemeldet) */
    case Show;
    /** POST api/v1/locations {name, kind?, parent_id?} (Admin) */
    case Create;
    /** POST api/v1/locations/{id}/rename {name} (Admin) */
    case Rename;
    /** POST api/v1/locations/{id}/lock {} (Admin) */
    case Lock;
    /** POST api/v1/locations/{id}/unlock {} (Admin) */
    case Unlock;
}
