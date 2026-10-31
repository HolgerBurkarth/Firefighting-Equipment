<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

/**
 * Sammelaktion der Geräteliste (DeviceBulkHandler, Admin).
 */
enum DeviceBulkAction
{
    /** POST api/v1/devices/bulk/inspection-due {devices: [{id, version, next_inspection_due}], comment?} */
    case InspectionDue;
    /** POST api/v1/devices/bulk/state {devices: [{id, version, location_id?, status?, client_ref?}], comment?} */
    case State;
}
