<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Permission\Restriction;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Einschränkung „Bei Einmalgeräten nur benutzt melden“ (Phase 2, P2.3): Eine Rolle mit
 * Restriction::SingleUseUsedOnly darf Einmalgeräte weder umbuchen noch auf einen anderen Status
 * setzen (POST devices/{id}/state, POST devices/bulk/state); „Benutzt melden“ bleibt erlaubt.
 */
final class SingleUseGuard
{
    private function __construct()
    {
    }

    /**
     * @throws HttpException 403 restricted_action
     */
    public static function check(Permissions $permissions, DeviceRepository $devices, Tenant $tenant, int $deviceId): void
    {
        if (!$permissions->restricted(Restriction::SingleUseUsedOnly)) {
            return;
        }
        // Unbekannt oder außerhalb des Datenfilters: die Buchung meldet 404 wie sonst.
        if ($devices->find($tenant, $deviceId)?->deviceTypeSingleUse === true) {
            throw HttpException::restrictedAction();
        }
    }
}
