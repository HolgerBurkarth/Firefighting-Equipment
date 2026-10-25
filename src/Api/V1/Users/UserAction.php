<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Users;

/**
 * Aktion der Benutzerverwaltung auf ein einzelnes Konto (UserActionHandler).
 */
enum UserAction
{
    /** GET api/v1/users/{id} */
    case Show;
    /** POST api/v1/users/{id}/approve {display_name, role} */
    case Approve;
    /** POST api/v1/users/{id}/reject {} */
    case Reject;
    /** POST api/v1/users/{id}/lock {} */
    case Lock;
    /** POST api/v1/users/{id}/unlock {} */
    case Unlock;
    /** POST api/v1/users/{id}/role {role} */
    case ChangeRole;
    /** POST api/v1/users/{id}/display-name {display_name} */
    case ChangeDisplayName;
    /** DELETE api/v1/users/{id} {} */
    case Delete;
}
