<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Maintenance;

/**
 * Sammelaktion der Seite „Wartung“ (MaintenanceMoveHandler).
 */
enum MaintenanceMove
{
    /** POST api/v1/maintenance/to-workshop {devices: [{id, version}], comment?}: in die Werkstatt, Status in Wartung */
    case ToWorkshop;
    /** POST api/v1/maintenance/back {devices: [{id, version}], comment?}: ins Standard-Lager, Status einsatzbereit */
    case Back;
}
