<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

/**
 * Aktion auf Geräte (DeviceActionHandler).
 */
enum DeviceAction
{
    /** GET api/v1/devices/{id} (angemeldet) */
    case Show;
    /** POST api/v1/devices {identifier, device_type_id, location_id, status?, …, comment?} (Admin) */
    case Create;
    /** POST api/v1/devices/{id}/fields {version, comment?, Felder …} (Admin) */
    case UpdateFields;
    /** POST api/v1/devices/{id}/inspection-due {version, next_inspection_due, comment?} (Admin) */
    case SetInspectionDue;
    /** POST api/v1/devices/{id}/retire {version, comment?} (Admin) */
    case Retire;
    /** POST api/v1/devices/{id}/reinstate {version, status, location_id?, identifier?, device_type_id?, comment?} (Admin) */
    case Reinstate;
}
