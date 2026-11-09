import type { DeviceListItem } from '../api/devices';
import { daysBetween, localDateKey } from './changeTime';

/**
 * Seite „Wartung“ (Phase 2, P2.4): „seit“ je Ausrüstung und die Transportliste. „Seit“ ist die Aktionszeit
 * der letzten Umbuchung oder Statusänderung (`state_changed_at`, G5: kein Zwischenspeicher).
 */

const HOUR_MS = 3_600_000;

/** „seit gerade eben“ (unter einer Stunde), „seit 2 Std.“, „seit 1 Tag“, „seit 3 Tagen“. */
export function sinceText(stateChangedAt: string | null, now: Date): string {
    if (stateChangedAt === null) {
        return '–';
    }
    const time = new Date(stateChangedAt);
    const diff = now.getTime() - time.getTime();
    if (diff < HOUR_MS) {
        return 'seit gerade eben';
    }
    const days = daysBetween(localDateKey(time), localDateKey(now));
    if (days < 1) {
        return `seit ${String(Math.floor(diff / HOUR_MS))} Std.`;
    }
    return days === 1 ? 'seit 1 Tag' : `seit ${String(days)} Tagen`;
}

/** Eine Zeile je Ausrüstung: „M1 · Atemschutzmaske · defekt · HLF 20 / G1“ (zum Einfügen in eine Mail). */
export function transportLine(device: DeviceListItem): string {
    return [
        device.identifier,
        device.deviceType.name,
        device.statusLabel,
        device.location.path,
    ].join(' · ');
}

/** Transportliste: Titel mit Anzahl, dann die Zeilen. */
export function transportText(title: string, devices: readonly DeviceListItem[]): string {
    return [`${title} (${String(devices.length)})`, ...devices.map(transportLine)].join('\n');
}
