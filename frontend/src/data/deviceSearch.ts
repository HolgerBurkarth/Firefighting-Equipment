import type { DeviceListItem } from '../api/devices';

/** Höchstens so viele Treffer zeigt die Suche (KONZEPT.md, "User am Handy – Entscheidungen", 20). */
export const MAX_SEARCH_HITS = 50;

/**
 * Vergleichsform der Suche: ohne Groß-/Kleinschreibung und ohne Akzente, ß = ss („Dräger“ findet
 * „drager“ und umgekehrt), ähnlich `LIKE` unter `utf8mb4_unicode_ci` bei der Suche auf dem Server.
 */
export function searchKey(value: string): string {
    return value.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replace(/ß/g, 'ss');
}

/** Wörter der Eingabe in Vergleichsform; leer = keine Suche. */
export function searchTokens(query: string): string[] {
    return searchKey(query)
        .split(/\s+/u)
        .filter((token) => token !== '');
}

function haystack(device: DeviceListItem): string {
    return [
        device.identifier,
        device.scanCode,
        device.serialNumber,
        device.manufacturer,
        device.model,
        device.deviceType.name,
        device.location.path,
    ]
        .filter((value): value is string => value !== null)
        .map(searchKey)
        .join('\n');
}

export interface SearchResult {
    /** Höchstens `limit` Treffer. */
    readonly hits: readonly DeviceListItem[];
    /** Anzahl aller Treffer (mehr als `hits` → „weiter eingrenzen“). */
    readonly total: number;
}

/**
 * Lokale Suche: Jedes Wort muss in Kennung, Scan-Code, Seriennummer, Hersteller, Modell, Gerätetyp
 * oder Standortpfad vorkommen (UND). Reihenfolge: Kennung gleich der Eingabe, dann Kennung beginnt
 * mit dem ersten Wort, dann der Rest; jeweils in der Reihenfolge der Liste (natürlich nach Kennung).
 */
export function searchDevices(
    devices: readonly DeviceListItem[],
    query: string,
    limit = MAX_SEARCH_HITS,
): SearchResult {
    const tokens = searchTokens(query);
    if (tokens.length === 0) {
        return { hits: [], total: 0 };
    }
    const whole = tokens.join(' ');
    const first = tokens[0] ?? '';
    const ranked: { device: DeviceListItem; rank: number; index: number }[] = [];
    devices.forEach((device, index) => {
        const text = haystack(device);
        if (!tokens.every((token) => text.includes(token))) {
            return;
        }
        const identifier = searchKey(device.identifier);
        let rank = 2;
        if (identifier === whole) {
            rank = 0;
        } else if (identifier.startsWith(first)) {
            rank = 1;
        }
        ranked.push({ device, rank, index });
    });
    ranked.sort((a, b) => a.rank - b.rank || a.index - b.index);
    return { hits: ranked.slice(0, limit).map((entry) => entry.device), total: ranked.length };
}

/**
 * Schlüssel der Kennung wie `identifier_key` im Backend: Kleinschreibung, großes ẞ als ß
 * (`toLowerCase` macht beides); ß ≠ ss und é ≠ e (anders als die Suche).
 */
export function identifierKey(identifier: string): string {
    return identifier.toLowerCase();
}

/**
 * Gerät zu einem gescannten oder per Enter bestätigten Code (KONZEPT.md, Entscheidung 17): zuerst
 * der Scan-Code exakt (wie im Backend, Groß-/Kleinschreibung zählt), dann die Kennung ohne Groß-/
 * Kleinschreibung. Leerraum am Rand zählt nicht (Scanner hängen oft ein Zeilenende an).
 */
export function findByCode(
    devices: readonly DeviceListItem[],
    code: string,
): DeviceListItem | null {
    const trimmed = code.replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, '');
    if (trimmed === '') {
        return null;
    }
    const byScanCode = devices.find((device) => device.scanCode === trimmed);
    if (byScanCode !== undefined) {
        return byScanCode;
    }
    // Mehrere Leerzeichen fasst das Backend in Kennungen zu einem zusammen (DeviceIdentifier).
    const key = identifierKey(trimmed.replace(/ {2,}/g, ' '));
    return devices.find((device) => identifierKey(device.identifier) === key) ?? null;
}
