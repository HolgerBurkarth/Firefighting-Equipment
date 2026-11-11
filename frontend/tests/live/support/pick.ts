import type { LiveDevice } from './browser';
import { IDENTIFIER } from './guard';

export interface Pick {
    booking: LiveDevice | null;
    used: LiveDevice | null;
    swapOut: LiveDevice | null;
    swapIn: LiveDevice | null;
    lateOut: LiveDevice | null;
    lateIn: LiveDevice | null;
}

/**
 * Wählt aus der Ausrüstung M90–M99 aus, was der Lauf braucht: zwei Paare gleichen Typs an
 * verschiedenen Standorten (Tausch), je eine Ausrüstung zum Umbuchen und für „Benutzt melden“.
 * Nur einsatzbereite, für beide Konten sichtbare Ausrüstung, keine Einmalgeräte, nie doppelt.
 */
export function pickDevices(all: LiveDevice[], visibleToUser2: ReadonlySet<number>): Pick {
    const usable = all
        .filter(
            (device) =>
                IDENTIFIER.test(device.identifier) &&
                visibleToUser2.has(device.id) &&
                device.device_type.single_use !== true &&
                device.status === 'ready',
        )
        .sort((a, b) => a.identifier.localeCompare(b.identifier, 'de', { numeric: true }));
    const taken = new Set<number>();
    const pair = (): [LiveDevice, LiveDevice] | null => {
        for (const out of usable) {
            if (taken.has(out.id)) {
                continue;
            }
            const replacement = usable.find(
                (candidate) =>
                    !taken.has(candidate.id) &&
                    candidate.id !== out.id &&
                    candidate.device_type.id === out.device_type.id &&
                    candidate.location.id !== out.location.id,
            );
            if (replacement) {
                taken.add(out.id);
                taken.add(replacement.id);
                return [out, replacement];
            }
        }
        return null;
    };
    const single = (): LiveDevice | null => {
        const device = usable.find((candidate) => !taken.has(candidate.id));
        if (device) {
            taken.add(device.id);
        }
        return device ?? null;
    };
    const first = pair();
    const second = pair();
    return {
        swapOut: first?.[0] ?? null,
        swapIn: first?.[1] ?? null,
        lateOut: second?.[0] ?? null,
        lateIn: second?.[1] ?? null,
        booking: single(),
        used: single(),
    };
}
