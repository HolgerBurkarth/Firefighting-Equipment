<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Über welchen Weg eine Änderung kam (fe_device_events.channel, fe_device_notice_actions.channel;
 * Migrationen 0047, 0048): die App bzw. REST-API oder ein Werkzeug des Claude-Zugangs (Verlauf,
 * CSV und Log-Mails zeigen dann „über Claude“).
 */
enum EventChannel: string
{
    case App = 'app';
    case Claude = 'claude';

    /** Zusatz hinter dem Namen, z. B. „Erika (über Claude)“; leer bei der App. */
    public function suffix(): string
    {
        return $this === self::Claude ? ' (über Claude)' : '';
    }
}
