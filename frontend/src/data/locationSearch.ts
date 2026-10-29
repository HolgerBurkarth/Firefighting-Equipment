import type { Location, LocationKind } from '../api/locations';
import { searchKey, searchTokens } from './deviceSearch';

/**
 * Standortwahl beim Umbuchen (KONZEPT.md, "User am Handy", Standortauswahl): ohne Suchbegriff alle
 * Standorte als Gruppen (ein Standort mit Unterstandorten klappt auf, erster Eintrag „Ganzes
 * Fahrzeug, ohne Fach“), mit Suchbegriff eine flache Trefferliste. Gesperrte fehlen.
 */

/** Erster Eintrag einer aufgeklappten Gruppe: der Standort selbst, ohne Unterstandort. */
export const WHOLE_LABELS: Readonly<Record<LocationKind, string>> = {
    vehicle: 'Ganzes Fahrzeug, ohne Fach',
    room: 'Ganzer Raum, ohne Fach',
    external: 'Ganzer Standort, ohne Fach',
};

/** Wählbarer Eintrag (Standort der Ebene 1 oder Unterstandort). */
export interface PickerEntry {
    readonly id: number;
    /** Angezeigter Name (bei „Ganzes Fahrzeug“ der Text aus WHOLE_LABELS). */
    readonly label: string;
    /** Pfad wie im Backend: „HLF 20 / G1“ bzw. der Name bei Ebene 1. */
    readonly path: string;
    readonly kind: LocationKind;
    readonly level: 1 | 2;
    /** Name des übergeordneten Standorts (nur Unterstandorte), sonst null. */
    readonly parentName: string | null;
    /** Geräte dort (Ebene 1 einschließlich der Unterstandorte); null = keine Angabe. */
    readonly deviceCount: number | null;
    readonly whole: boolean;
}

/** Standort der Ebene 1 in der Liste ohne Suchbegriff. */
export interface PickerGroup {
    readonly entry: PickerEntry;
    /** Leer: Antippen wählt den Standort; sonst klappt er auf (ganzer Standort, dann die Fächer). */
    readonly children: readonly PickerEntry[];
}

function groupEntry(location: Location): PickerEntry {
    return {
        id: location.id,
        label: location.name,
        path: location.name,
        kind: location.kind,
        level: 1,
        parentName: null,
        deviceCount: location.deviceCountTotal,
        whole: false,
    };
}

function childEntries(location: Location): PickerEntry[] {
    return location.children
        .filter((child) => child.active)
        .map((child) => ({
            id: child.id,
            label: child.name,
            path: `${location.name} / ${child.name}`,
            kind: child.kind,
            level: 2,
            parentName: location.name,
            deviceCount: child.deviceCount,
            whole: false,
        }));
}

export function locationGroups(tree: readonly Location[]): PickerGroup[] {
    return tree
        .filter((location) => location.active)
        .map((location) => {
            const children = childEntries(location);
            return {
                entry: groupEntry(location),
                children:
                    children.length === 0
                        ? []
                        : [
                              {
                                  ...groupEntry(location),
                                  label: WHOLE_LABELS[location.kind],
                                  deviceCount: null,
                                  whole: true,
                              },
                              ...children,
                          ],
            };
        });
}

/**
 * Trefferliste: alle Wörter (UND, Vergleichsform wie die Gerätesuche) im Namen eines Standorts der
 * Ebene 1 bzw. im ganzen Pfad eines Unterstandorts („hlf g1“). Reihenfolge wie der Baum, ein
 * Unterstandort direkt nach seinem Standort.
 */
export function searchLocations(tree: readonly Location[], query: string): PickerEntry[] {
    const tokens = searchTokens(query);
    if (tokens.length === 0) {
        return [];
    }
    const matches = (text: string): boolean => {
        const key = searchKey(text);
        return tokens.every((token) => key.includes(token));
    };
    const hits: PickerEntry[] = [];
    for (const location of tree.filter((entry) => entry.active)) {
        const entry = groupEntry(location);
        if (matches(entry.path)) {
            hits.push(entry);
        }
        hits.push(...childEntries(location).filter((child) => matches(child.path)));
    }
    return hits;
}

/** ID des Standorts der Ebene 1, zu dem `id` gehört (er selbst oder sein übergeordneter). */
export function groupOf(tree: readonly Location[], id: number): number | null {
    for (const location of tree) {
        if (location.id === id || location.children.some((child) => child.id === id)) {
            return location.id;
        }
    }
    return null;
}

/** „1 Gerät“, „3 Geräte“. */
export function deviceCountText(count: number): string {
    return count === 1 ? '1 Gerät' : `${String(count)} Geräte`;
}
