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

function nameText(device: DeviceListItem): string {
    return [device.identifier, device.deviceType.name, device.location.path]
        .map(searchKey)
        .join('\n');
}

function allText(device: DeviceListItem): string {
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

/** Vergleichsform einer Nummer ohne Leerzeichen („m 8“ = „M8“). */
function compactKey(value: string): string {
    return searchKey(value).replace(/\s+/gu, '');
}

/** Ohne führende Nullen in jeder Zahl („m08“ = „m8“, „007“ = „7“). */
function withoutLeadingZeros(value: string): string {
    return value.replace(/\d+/g, (digits) => digits.replace(/^0+(?=\d)/, ''));
}

/** Zahlen einer Nummer („HRT 8“ → 8, „M8-2“ → 8 und 2), ohne führende Nullen. */
function numbersOf(identifier: string): string[] {
    return (identifier.match(/\d+/g) ?? []).map(withoutLeadingZeros);
}

/** Wortteile einer Nummer: an Leerzeichen, -, /, . und am Wechsel von Buchstaben und Ziffern. */
function identifierParts(identifier: string): string[] {
    return searchKey(identifier)
        .split(/[\s\-/.]+/u)
        .flatMap((part) => part.match(/\d+|\D+/gu) ?? [])
        .filter((part) => part !== '');
}

/** Wörter eines Namens bzw. Standortpfads in Vergleichsform. */
function wordsOf(value: string): string[] {
    return searchKey(value)
        .split(/[\s\-/.]+/u)
        .filter((word) => word !== '');
}

const DIGITS = /^\d+$/;

/** Ab dieser Länge zählt ein Wort in Stufe 4 auch mitten im Wort („maske“ in „Atemschutzmaske“). */
export const WORD_PART_LENGTH = 3;

/**
 * Stufen der Suche (T28, Wunsch Holger 23.11.2026), die erste mit Treffern gewinnt, die übrigen
 * sind aufklappbar:
 * 1 Nummer genau (ohne Groß-/Kleinschreibung und Leerzeichen, „m 8“ = „M8“) oder Scan-Code genau;
 * 2 Nummer ohne führende Nullen genau („m08“ = „M8“) bzw. bei reinen Ziffern eine Zahl der Nummer
 *   genau („8“ → M8, HRT 8, nicht M18);
 * 3 Nummer oder ein Wortteil der Nummer beginnt mit der Eingabe („m1“ → M10, „12“ → Lager-12/3);
 * 4 jedes Wort steht in einem Wort von Nummer, Ausrüstungstyp oder Standort, kurze Wörter (unter
 *   `WORD_PART_LENGTH` Zeichen) am Wortanfang („lf 8“ → LF 8, nicht HLF 20; „maske 8“ → M8), Zahlen
 *   als Zahl der Nummer genau bzw. als Zahl im Standort am Anfang, ohne führende Nullen;
 * 5 jedes Wort steht irgendwo in Nummer, Ausrüstungstyp oder Standort („maske 8“ → M18);
 * 6 jedes Wort steht irgendwo, auch in Scan-Code, Seriennummer, Hersteller und Modell; kurze Zahlen
 *   (bis `SHORT_NUMBER_LENGTH` Ziffern) dabei nur in der Nummer, sie stecken sonst in fast jeder
 *   Seriennummer.
 */
export type SearchTier = 1 | 2 | 3 | 4 | 5 | 6;

function tierOf(
    device: DeviceListItem,
    query: string,
    tokens: readonly string[],
): SearchTier | null {
    const compact = compactKey(query);
    const identifier = compactKey(device.identifier);
    if (
        identifier === compact ||
        (device.scanCode !== null && device.scanCode === trimCode(query))
    ) {
        return 1;
    }
    const numbers = numbersOf(device.identifier);
    const number = withoutLeadingZeros(compact);
    if (
        withoutLeadingZeros(identifier) === number ||
        (DIGITS.test(compact) && numbers.includes(number))
    ) {
        return 2;
    }
    const parts = identifierParts(device.identifier);
    if (identifier.startsWith(compact) || parts.some((part) => part.startsWith(compact))) {
        return 3;
    }
    const nameWords = [...wordsOf(device.deviceType.name), ...wordsOf(device.location.path)];
    const words = [...parts, ...nameWords];
    const atWordStart = (token: string): boolean =>
        DIGITS.test(token)
            ? numbers.includes(withoutLeadingZeros(token)) ||
              nameWords.some(
                  (word) =>
                      DIGITS.test(word) &&
                      withoutLeadingZeros(word).startsWith(withoutLeadingZeros(token)),
              )
            : words.some((word) =>
                  token.length >= WORD_PART_LENGTH ? word.includes(token) : word.startsWith(token),
              );
    if (tokens.every(atWordStart)) {
        return 4;
    }
    const names = nameText(device);
    if (tokens.every((token) => names.includes(token))) {
        return 5;
    }
    const text = allText(device);
    const short = (token: string): boolean =>
        DIGITS.test(token) && token.length <= SHORT_NUMBER_LENGTH;
    return tokens.every((token) =>
        short(token) ? searchKey(device.identifier).includes(token) : text.includes(token),
    )
        ? 6
        : null;
}

/** Zahlen bis zu dieser Länge sucht Stufe 6 nur in der Nummer (stecken in fast jeder Seriennummer). */
export const SHORT_NUMBER_LENGTH = 2;

export interface SearchResult {
    /** Treffer der besten Stufe, höchstens `limit`. */
    readonly hits: readonly DeviceListItem[];
    /** Anzahl aller Treffer der besten Stufe (mehr als `hits` → „weiter eingrenzen“). */
    readonly total: number;
    /** Treffer der schwächeren Stufen (aufklappbar), höchstens `limit`. */
    readonly more: readonly DeviceListItem[];
    /** Anzahl aller Treffer der schwächeren Stufen. */
    readonly moreTotal: number;
}

/** Alle Treffer nach Stufe, innerhalb einer Stufe in der Reihenfolge der Liste. */
export function rankDevices(
    devices: readonly DeviceListItem[],
    query: string,
): { readonly device: DeviceListItem; readonly tier: SearchTier }[] {
    const tokens = searchTokens(query);
    if (tokens.length === 0) {
        return [];
    }
    const ranked: { device: DeviceListItem; tier: SearchTier; index: number }[] = [];
    devices.forEach((device, index) => {
        const tier = tierOf(device, query, tokens);
        if (tier !== null) {
            ranked.push({ device, tier, index });
        }
    });
    ranked.sort((a, b) => a.tier - b.tier || a.index - b.index);
    return ranked.map(({ device, tier }) => ({ device, tier }));
}

/** Gestufte Suche (siehe `SearchTier`): die beste Stufe als Treffer, die übrigen als `more`. */
export function searchDevices(
    devices: readonly DeviceListItem[],
    query: string,
    limit = MAX_SEARCH_HITS,
): SearchResult {
    const ranked = rankDevices(devices, query);
    const best = ranked[0]?.tier;
    const primary = ranked.filter((entry) => entry.tier === best).map((entry) => entry.device);
    const rest = ranked.filter((entry) => entry.tier !== best).map((entry) => entry.device);
    return {
        hits: primary.slice(0, limit),
        total: primary.length,
        more: rest.slice(0, limit),
        moreTotal: rest.length,
    };
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
