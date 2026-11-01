import type { Location } from '../api/locations';
import { RecentIds } from './recentIds';

/**
 * Zuletzt benutzte Standorte beim Umbuchen (KONZEPT.md, "User am Handy – Entscheidungen", 21): die
 * letzten 3 eigenen Ziele, lokal je Stadt und Konto, beim Abmelden gelöscht. Angezeigt wird davon
 * nur das neueste außer dem aktuellen Standort (`SHOWN_RECENT_LOCATIONS`, T2); die übrigen sind
 * die Reserve, wenn das neueste gerade der aktuelle oder gesperrt ist.
 */
export const MAX_RECENT_LOCATIONS = 3;

export const SHOWN_RECENT_LOCATIONS = 1;

export const recentLocations = new RecentIds('ff-recent-locations', MAX_RECENT_LOCATIONS);

/** Standort zur Auswahl, `path` wie im Backend („HLF 20 / G1“ bzw. der Name bei Ebene 1). */
export interface LocationChoice {
    readonly id: number;
    readonly path: string;
}

/**
 * Aktive Standorte des Baums nach ID (GET locations enthält ohne Parameter keine gesperrten; ein
 * aktiver Unterstandort hat immer einen aktiven übergeordneten Standort).
 */
export function locationPaths(tree: readonly Location[]): Map<number, LocationChoice> {
    const paths = new Map<number, LocationChoice>();
    for (const location of tree) {
        if (!location.active) {
            continue;
        }
        paths.set(location.id, { id: location.id, path: location.name });
        for (const child of location.children) {
            if (child.active) {
                paths.set(child.id, { id: child.id, path: `${location.name} / ${child.name}` });
            }
        }
    }
    return paths;
}

export interface BookingLocationChoice extends LocationChoice {
    readonly hint: 'aktuell' | 'gewählt' | 'zuletzt';
}

/**
 * Standorte zum Antippen beim Umbuchen: der aktuelle, ein in der Standortwahl gewählter (wenn er
 * nicht ohnehin dasteht) und `SHOWN_RECENT_LOCATIONS` eigene Ziele; nur bekannte aktive aus `paths`.
 */
export function bookingLocationChoices(
    current: LocationChoice,
    chosenId: number | null,
    recentIds: readonly number[],
    paths: ReadonlyMap<number, LocationChoice>,
): BookingLocationChoice[] {
    const recent = recentIds
        .filter((id) => id !== current.id)
        .map((id) => paths.get(id))
        .filter((known): known is LocationChoice => known !== undefined)
        .slice(0, SHOWN_RECENT_LOCATIONS);
    const choices: BookingLocationChoice[] = [
        { id: current.id, path: current.path, hint: 'aktuell' },
    ];
    const chosen = chosenId === null ? undefined : paths.get(chosenId);
    if (
        chosen !== undefined &&
        chosen.id !== current.id &&
        !recent.some((known) => known.id === chosen.id)
    ) {
        choices.push({ ...chosen, hint: 'gewählt' });
    }
    for (const known of recent) {
        choices.push({ ...known, hint: 'zuletzt' });
    }
    return choices;
}
