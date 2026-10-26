<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\DeviceTypes;

/**
 * Aktion des Admins auf Gerätetypen (DeviceTypeActionHandler).
 */
enum DeviceTypeAction
{
    /** POST api/v1/device-types {name} */
    case Create;
    /** POST api/v1/device-types/{id}/rename {name} */
    case Rename;
    /** POST api/v1/device-types/{id}/deactivate {} */
    case Deactivate;
    /** POST api/v1/device-types/{id}/activate {} */
    case Activate;
}
