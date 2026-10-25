<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Start-Gerätetypen jeder Stadt. Neue Städte erhalten sie beim Anlegen (TenantRepository::create),
 * bestehende hat Migration 0012 mit derselben Liste ergänzt.
 */
final class DefaultDeviceTypes
{
    /** @var list<string> */
    public const array NAMES = ['Atemschutzmaske', 'Atemschutzgerät', 'Funkgerät', 'Wärmebildkamera'];

    private function __construct()
    {
    }
}
