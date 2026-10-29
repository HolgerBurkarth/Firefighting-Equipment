import type { Location } from '../api/locations';
import { RecentIds } from './recentIds';

/**
 * Zuletzt benutzte Standorte beim Umbuchen (KONZEPT.md, "User am Handy – Entscheidungen", 21): die
 * letzten 3 eigenen Ziele, lokal je Stadt und Konto, beim Abmelden gelöscht.
 */
export const MAX_RECENT_LOCATIONS = 3;

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
