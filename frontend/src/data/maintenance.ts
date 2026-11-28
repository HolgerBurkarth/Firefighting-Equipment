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

/**
 * Zustand einer Ausrüstung in der Werkstatt (G9, Benutzertest Gerätewart am Handy): `done` = schon
 * einsatzbereit, also fertig gewartet und bereit fürs Lager; `defective` = defekt, bleibt; sonst `working`
 * (in Wartung bzw. benutzt, noch zu tun).
 */
export type WorkshopState = 'done' | 'working' | 'defective';

export function workshopState(device: DeviceListItem): WorkshopState {
    if (device.status === 'ready') {
        return 'done';
    }
    return device.status === 'defective' ? 'defective' : 'working';
}

const WORKSHOP_ORDER: Readonly<Record<WorkshopState, number>> = {
    done: 0,
    working: 1,
    defective: 2,
};

/** Werkstatt: fertige zuerst, dann in Arbeit, zuletzt defekte; innerhalb wie der Server (am längsten wartende zuerst). */
export function orderWorkshop(devices: readonly DeviceListItem[]): DeviceListItem[] {
    return devices
        .map((device, index) => ({ device, index }))
        .sort(
            (a, b) =>
                WORKSHOP_ORDER[workshopState(a.device)] - WORKSHOP_ORDER[workshopState(b.device)] ||
                a.index - b.index,
        )
        .map((entry) => entry.device);
}

/** „2 fertig · 3 in Wartung · 1 defekt“ (Stufen ohne Ausrüstung entfallen), leer ohne Ausrüstung. */
export function workshopSummary(devices: readonly DeviceListItem[]): string {
    const counts: Record<WorkshopState, number> = { done: 0, working: 0, defective: 0 };
    for (const device of devices) {
        counts[workshopState(device)] += 1;
    }
    return [
        counts.done > 0 ? `${String(counts.done)} fertig` : null,
        counts.working > 0 ? `${String(counts.working)} in Wartung` : null,
        counts.defective > 0 ? `${String(counts.defective)} defekt` : null,
    ]
        .filter((part) => part !== null)
        .join(' · ');
}

/** „fertig seit 2 Std.“ bzw. „in Wartung seit 3 Tagen“ (Werkstatt); die Sammelstelle nur „seit …“. */
export function workshopSinceText(device: DeviceListItem, now: Date): string {
    const since = sinceText(device.stateChangedAt, now);
    if (since === '–') {
        return since;
    }
    switch (workshopState(device)) {
        case 'done':
            return `fertig ${since}`;
        case 'defective':
            return `defekt ${since}`;
        default:
            return `in Wartung ${since}`;
    }
}
