<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Roles;

/**
 * Aktion des Super-Admins auf die Rollen einer Stadt (RoleHandler).
 */
enum RoleAction
{
    /** GET api/v1/tenants/{id}/roles → {roles} */
    case List;
    /** POST api/v1/tenants/{id}/roles {ganzer Stand} bzw. {copy_of, name?} → 201 {role} */
    case Create;
    /** POST api/v1/tenants/{id}/roles/import {source_role_id, name?} → 201 {role} */
    case Import;
    /** GET api/v1/tenants/{id}/roles/{role} → {role} */
    case Show;
    /** POST api/v1/tenants/{id}/roles/{role} {version, ganzer Stand} → {role} */
    case Save;
    /** DELETE api/v1/tenants/{id}/roles/{role} {} → {deleted_role_id} */
    case Delete;
    /** GET api/v1/tenants/{id}/role-filter-options → {locations, device_types} */
    case FilterOptions;
}
