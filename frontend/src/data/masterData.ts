import type { DeviceType } from '../api/deviceTypes';
import type { Location, SubLocation } from '../api/locations';
import { searchKey, searchTokens } from './deviceSearch';

/** Höchstlängen wie LocationName und DeviceTypeName in PHP. */
export const LOCATION_NAME_MAX_LENGTH = 100;
export const DEVICE_TYPE_NAME_MAX_LENGTH = 50;

/** Leerraum, den PHP `trim()` entfernt (DeviceNameRules), nicht jeder Unicode-Leerraum. */
export function phpTrim(value: string): string {
    return value.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '');
}

/**
 * Prüft einen Namen wie DeviceNameRules::check (Live-Prüfung im Formular): nicht leer, höchstens
 * `maxLength` Zeichen nach dem Entfernen von Leerraum am Rand, keine Steuerzeichen. null = in
 * Ordnung; verbindlich bleibt der Server.
 */
export function nameProblem(value: string, maxLength: number): string | null {
    const name = phpTrim(value);
    if (name === '') {
        return 'Bitte einen Namen eingeben.';
    }
    const length = Array.from(name).length;
    if (length > maxLength) {
        return `Höchstens ${String(maxLength)} Zeichen (jetzt ${String(length)}).`;
    }
    if (/\p{Cc}/u.test(name)) {
        return 'Keine Zeilenumbrüche oder Steuerzeichen.';
    }
    return null;
}

/**
 * Gleicher Name für den Hinweis beim Tippen (ohne Groß-/Kleinschreibung und Akzente, ß = ss; wie
 * ungefähr `utf8mb4_unicode_ci`). Ob ein Name vergeben ist, entscheidet der Server (409 name_taken).
 */
export function sameName(a: string, b: string): boolean {
    return searchKey(phpTrim(a)) === searchKey(phpTrim(b));
}

/** Natürliche Reihenfolge wie NATURAL_SORT_KEY (HLF 2 vor HLF 10), ohne Groß-/Kleinschreibung. */
const COLLATOR = new Intl.Collator('de', { numeric: true, sensitivity: 'base' });

export function compareNames(a: string, b: string): number {
    return COLLATOR.compare(a, b);
}

function countText(count: number, one: string, many: string): string {
    return count === 1 ? `1 ${one}` : `${String(count)} ${many}`;
}

/**
 * Warum sich ein Standort nicht sperren lässt (wie LocationRepository::lock: keine nicht
 * ausgesonderten Geräte direkt dort, keine aktiven Fächer), sonst null. Die Zahlen stammen aus dem
 * geladenen Baum; ist er veraltet, lehnt der Server mit 409 ab.
 */
export function lockBlocker(location: Location | SubLocation): string | null {
    const sublocations =
        'children' in location ? location.children.filter((child) => child.active).length : 0;
    const parts: string[] = [];
    if (location.deviceCount > 0) {
        parts.push(countText(location.deviceCount, 'Gerät', 'Geräte'));
    }
    if (sublocations > 0) {
        parts.push(countText(sublocations, 'aktives Fach', 'aktive Fächer'));
    }
    if (parts.length === 0) {
        return null;
    }
    // Einzahl nur bei genau einem Gerät bzw. Fach („Hier liegt noch 1 aktives Fach.“).
    const verb =
        parts.length === 1 && location.deviceCount + sublocations === 1 ? 'liegt' : 'liegen';
    return `Sperren geht nur, wenn der Standort leer ist. Hier ${verb} noch ${parts.join(' und ')}.`;
}

/** Eine Gruppe im Baum der Verwaltung: Standort der Ebene 1 mit den angezeigten Fächern. */
export interface LocationTreeGroup {
    readonly location: Location;
    readonly children: readonly SubLocation[];
}

/**
 * Baum der Verwaltung, gefiltert: ohne `showLocked` ohne gesperrte Standorte und Fächer. Mit
 * Suchbegriff (Wörter mit UND, wie die Standortwahl): Passt der Name der Ebene 1, erscheint sie mit
 * allen Fächern; sonst nur mit den Fächern, deren Pfad „HLF 20 / G1“ passt.
 */
export function filterLocationTree(
    tree: readonly Location[],
    query: string,
    showLocked: boolean,
): LocationTreeGroup[] {
    const tokens = searchTokens(query);
    const matches = (text: string): boolean => {
        const key = searchKey(text);
        return tokens.every((token) => key.includes(token));
    };
    const groups: LocationTreeGroup[] = [];
    for (const location of tree) {
        if (!showLocked && !location.active) {
            continue;
        }
        const visible = location.children.filter((child) => showLocked || child.active);
        if (tokens.length === 0 || matches(location.name)) {
            groups.push({ location, children: visible });
            continue;
        }
        const children = visible.filter((child) => matches(`${location.name} / ${child.name}`));
        if (children.length > 0) {
            groups.push({ location, children });
        }
    }
    return groups;
}

/** Anzahl gesperrter Standorte und Fächer (für „Gesperrte anzeigen (3)“). */
export function lockedCount(tree: readonly Location[]): number {
    let count = 0;
    for (const location of tree) {
        count += location.active ? 0 : 1;
        count += location.children.filter((child) => !child.active).length;
    }
    return count;
}

export type DeviceTypeSortKey = 'name' | 'devices';
export type SortDir = 'asc' | 'desc';

/**
 * Gerätetypen der Verwaltung: ohne `showInactive` nur aktive, gefiltert nach Wörtern im Namen,
 * sortiert nach Name (natürlich) oder Gerätezahl; bei Gleichstand nach Name aufsteigend.
 */
export function deviceTypeRows(
    types: readonly DeviceType[],
    query: string,
    showInactive: boolean,
    sort: DeviceTypeSortKey,
    dir: SortDir,
): DeviceType[] {
    const tokens = searchTokens(query);
    const rows = types.filter((type) => {
        if (!showInactive && !type.active) {
            return false;
        }
        const key = searchKey(type.name);
        return tokens.every((token) => key.includes(token));
    });
    const sign = dir === 'asc' ? 1 : -1;
    return rows.sort((a, b) => {
        const primary =
            sort === 'name' ? compareNames(a.name, b.name) : a.deviceCount - b.deviceCount;
        return primary === 0 ? compareNames(a.name, b.name) : sign * primary;
    });
}
