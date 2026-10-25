<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Tenants;

/**
 * Aktion des Super-Admins auf eine Stadt (TenantActionHandler).
 */
enum TenantAction
{
    /** GET api/v1/tenants/{id} */
    case Show;
    /** POST api/v1/tenants/{id}/rename {name} */
    case Rename;
    /** POST api/v1/tenants/{id}/lock {} */
    case Lock;
    /** POST api/v1/tenants/{id}/unlock {} */
    case Unlock;
}
