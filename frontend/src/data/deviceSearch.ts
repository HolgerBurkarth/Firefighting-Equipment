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
 * Großbuchstaben, die MariaDB unter `utf8mb4_unicode_ci` (Unicode-Stand von UCA 4.0.0) nicht
 * kleinschreibt, wohl aber `toLowerCase` (neuere Unicode-Versionen), als Bereiche von Codepunkten;
 * dazu alle außerhalb der BMP. Gemessen an der Datenbank, geprüft für jeden Groß- und Titelbuchstaben
 * über `tests/Fixtures/identifier-key-cases.json` (IdentifierKeyCasesTest, deviceSearch.spec.ts).
 */
const DATABASE_UNCASED: readonly (readonly [number, number])[] = [
    [0x220, 0x220],
    [0x23a, 0x37f],
    [0x3cf, 0x3d8],
    [0x3f4, 0x3ff],
    [0x48a, 0x48a],
    [0x4c0, 0x4c0],
    [0x4c5, 0x4c5],
    [0x4c9, 0x4c9],
    [0x4cd, 0x4cd],
    [0x4f6, 0x4f6],
    [0x4fa, 0x52e],
    [0x10a0, 0x1cbf],
    [0x1efa, 0x1efe],
    [0x2132, 0xa7f5],
];

/** Kleinschreibung wie `LOWER()` in MariaDB: je Zeichen (kein Sigma am Wortende), İ wird zu i. */
function databaseLowerCase(value: string): string {
    let result = '';
    for (const char of value) {
        const codePoint = char.codePointAt(0) ?? 0;
        if (codePoint === 0x130) {
            result += 'i';
        } else if (
            codePoint > 0xffff ||
            DATABASE_UNCASED.some(([from, to]) => codePoint >= from && codePoint <= to)
        ) {
            result += char;
        } else {
            result += char.toLowerCase();
        }
    }
    return result;
}

/**
 * Schlüssel der Kennung wie `identifier_key` im Backend (`REPLACE(LOWER(identifier), 'ẞ', 'ß')`):
 * Kleinschreibung wie die Datenbank, großes ẞ als ß; ß ≠ ss und é ≠ e (anders als die Suche).
 */
export function identifierKey(identifier: string): string {
    return databaseLowerCase(identifier).replace(/ẞ/g, 'ß');
}

/** Leerraum am Rand wie `ScanCode` im Backend (Scanner hängen oft ein Zeilenende an). */
export function trimCode(code: string): string {
    return code.replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, '');
}

/** Höchstlängen wie `ScanCode::MAX_LENGTH` und `DeviceIdentifier::MAX_LENGTH` im Backend. */
export const SCAN_CODE_MAX_LENGTH = 200;
export const IDENTIFIER_MAX_LENGTH = 30;

/**
 * Könnte der (getrimmte) Text ein Scan-Code sein (Regeln wie `ScanCode`)? Sonst lehnte der Server
 * `scan_code` mit 400 ab: Steuerzeichen außer EOT, GS, RS, Formatzeichen, U+2028/2029, zu lang.
 */
export function isPossibleScanCode(code: string): boolean {
    return (
        code !== '' &&
        Array.from(code).length <= SCAN_CODE_MAX_LENGTH &&
        !/[\u0000-\u0003\u0005-\u001c\u001f\u007f-\u009f\p{Cf}\u2028\u2029]/u.test(code)
    );
}

/**
 * Schlüssel einer eingegebenen oder gescannten Kennung: wie `DeviceIdentifier` (Leerraum am Rand
 * weg, mehrere Leerzeichen zu einem, zusammengesetzte Form), dann `identifierKey`.
 */
export function codeKey(code: string): string {
    return identifierKey(identifierText(code));
}

/** Kennung so, wie das Backend sie speichert (ohne Kleinschreibung), z. B. für `q`. */
export function identifierText(code: string): string {
    return code.normalize('NFC').trim().replace(/ {2,}/g, ' ');
}

/**
 * Gerät zu einem gescannten oder per Enter bestätigten Code (KONZEPT.md, Entscheidung 17): zuerst
 * der Scan-Code exakt (wie im Backend, Groß-/Kleinschreibung zählt), dann die Kennung ohne Groß-/
 * Kleinschreibung (`codeKey`, wie der UNIQUE-Index).
 */
export function findByCode(
    devices: readonly DeviceListItem[],
    code: string,
): DeviceListItem | null {
    const trimmed = trimCode(code);
    if (trimmed === '') {
        return null;
    }
    const byScanCode = devices.find((device) => device.scanCode === trimmed);
    if (byScanCode !== undefined) {
        return byScanCode;
    }
    const key = codeKey(trimmed);
    return devices.find((device) => identifierKey(device.identifier) === key) ?? null;
}
