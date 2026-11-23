// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    codeKey,
    findByCode,
    identifierKey,
    isPossibleScanCode,
    MAX_SEARCH_HITS,
    rankDevices,
    searchDevices,
    searchKey,
    searchTokens,
} from '../../src/data/deviceSearch';
import { device, devices } from './support/devices';

const LIST = [
    device(1, { identifier: 'M1' }),
    device(2, { identifier: 'M2', location: { id: 12, name: 'G2', path: 'HLF 20 / G2' } }),
    device(3, { identifier: 'M10' }),
    device(4, {
        identifier: 'PA 3',
        device_type: { id: 2, name: 'Atemschutzgerät' },
        location: { id: 20, name: 'Atemschutzwerkstatt', path: 'Atemschutzwerkstatt' },
        serial_number: 'SN-4711',
        manufacturer: 'Dräger',
        model: 'PSS 5000',
    }),
    device(5, { identifier: 'HRT 5', scan_code: '04006381333931' }),
    device(6, { identifier: 'Straße 1', device_type: { id: 3, name: 'Funkgerät' } }),
    device(7, { identifier: 'XM1' }),
];

function ids(query: string): number[] {
    return searchDevices(LIST, query).hits.map((d) => d.id);
}

/** Alle Treffer in der Reihenfolge der Stufen (Treffer, dann weitere). */
function allIds(query: string, list = LIST): number[] {
    return rankDevices(list, query).map((entry) => entry.device.id);
}

describe('searchKey', () => {
    it('ohne Groß-/Kleinschreibung und Akzente, ß = ss', () => {
        expect(searchKey('Dräger')).toBe('drager');
        expect(searchKey('Dräger')).toBe('drager');
        expect(searchKey('STRASSE')).toBe(searchKey('Straße'));
        expect(searchKey('Élan')).toBe('elan');
    });

    it('Wörter an Leerraum getrennt', () => {
        expect(searchTokens('  HLF\t20  g1 ')).toEqual(['hlf', '20', 'g1']);
        expect(searchTokens('   ')).toEqual([]);
    });
});

describe('searchDevices', () => {
    it('leere Eingabe sucht nicht', () => {
        expect(searchDevices(LIST, '  ')).toEqual({ hits: [], total: 0, more: [], moreTotal: 0 });
    });

    it.each([
        ['Nummer', 'pa 3', [4]],
        ['Scan-Code', '333931', [5]],
        ['Seriennummer', 'sn-47', [4]],
        ['Hersteller ohne Umlaut', 'drager', [4]],
        ['Modell', 'pss', [4]],
        ['Ausrüstungstyp', 'funkger', [6]],
        ['Standortpfad', 'werkstatt', [4]],
        ['ß = ss', 'strasse', [6]],
    ])('findet über %s', (_label, query, expected) => {
        expect(ids(query)).toEqual(expected);
    });

    it('mehrere Wörter: alle müssen passen (UND), auch über Felder', () => {
        expect(ids('hlf g2')).toEqual([2]);
        expect(ids('dräger werkstatt')).toEqual([4]);
        expect(ids('dräger hlf')).toEqual([]);
    });

    it('Nummer gleich der Eingabe zuerst, dann Nummer beginnt damit, dann der Rest', () => {
        // „m1“: M1 genau, M10 beginnt damit, XM1 enthält es nur.
        expect(ids('m1')).toEqual([1]);
        expect(allIds('m1')).toEqual([1, 3, 7]);
        const result = searchDevices(LIST, 'm1');
        expect(result.total).toBe(1);
        expect(result.more.map((d) => d.id)).toEqual([3, 7]);
        expect(result.moreTotal).toBe(2);
    });

    it('begrenzt auf 50 Treffer, total zählt alle', () => {
        const many = devices(1, 120);
        const result = searchDevices(many, 'm');
        expect(MAX_SEARCH_HITS).toBe(50);
        expect(result.hits).toHaveLength(50);
        expect(result.total).toBe(120);
        expect(result.hits[0]?.id).toBe(1);
    });
});

describe('findByCode', () => {
    it('Scan-Code exakt vor der Nummer', () => {
        const list = [device(1, { identifier: 'ABC' }), device(2, { scan_code: 'ABC' })];
        expect(findByCode(list, 'ABC')?.id).toBe(2);
    });

    it('Scan-Code mit Groß-/Kleinschreibung, Nummer ohne', () => {
        const list = [device(1, { identifier: 'M1', scan_code: 'Xy-1' })];
        expect(findByCode(list, 'xy-1')).toBeNull();
        expect(findByCode(list, 'Xy-1')?.id).toBe(1);
        expect(findByCode(list, 'm1')?.id).toBe(1);
    });

    it('Leerraum am Rand zählt nicht (Zeilenende des Scanners), Teiltreffer nicht', () => {
        expect(findByCode(LIST, ' 04006381333931\r\n')?.id).toBe(5);
        expect(findByCode(LIST, '0400638133393')).toBeNull();
        expect(findByCode(LIST, ' \n')).toBeNull();
    });

    it('mehrere Leerzeichen in der Nummer wie ein Leerzeichen', () => {
        expect(findByCode(LIST, 'PA  3')?.id).toBe(4);
    });

    it('Nummer: ß ≠ ss, großes ẞ = ß (wie identifier_key)', () => {
        expect(findByCode(LIST, 'STRASSE 1')).toBeNull();
        expect(findByCode(LIST, 'STRAẞE 1')?.id).toBe(6);
        expect(identifierKey('ẞ')).toBe('ß');
    });

    it('GS-Trennzeichen bleiben Teil des Scan-Codes', () => {
        const list = [device(1, { scan_code: '010400638133393110ABC\u001d21SN7' })];
        expect(findByCode(list, '010400638133393110ABC\u001d21SN7')?.id).toBe(1);
        expect(findByCode(list, '010400638133393110ABC21SN7')).toBeNull();
    });
});

interface KeyCase {
    readonly description: string;
    readonly input: string;
    readonly server: string;
}

const keyCases = JSON.parse(
    readFileSync(
        new URL('../../../tests/Fixtures/identifier-key-cases.json', import.meta.url),
        'utf8',
    ),
) as { cases: KeyCase[]; letters: KeyCase };

describe('gemeinsame Testfälle mit identifier_key (IdentifierKeyCasesTest)', () => {
    it.each(keyCases.cases.map((c) => [c.description, c] as const))('%s', (_, c) => {
        expect(codeKey(c.input)).toBe(c.server);
        // Gespeicherte Nummer (wie DeviceIdentifier) ergibt denselben Schlüssel.
        expect(identifierKey(c.input.normalize('NFC').trim().replace(/ {2,}/g, ' '))).toBe(
            c.server,
        );
    });

    it('jeder Groß- und Titelbuchstabe wie LOWER() in MariaDB', () => {
        const server = Array.from(keyCases.letters.server);
        const differing = Array.from(keyCases.letters.input)
            .filter((char, index) => identifierKey(char) !== server[index])
            .map((char) => `U+${(char.codePointAt(0) ?? 0).toString(16).toUpperCase()}`);
        expect(differing).toEqual([]);
    });

    it('die Datei enthält alle Groß- und Titelbuchstaben dieser Node-Version', () => {
        const known = new Set(Array.from(keyCases.letters.input));
        const missing: string[] = [];
        for (let codePoint = 0x41; codePoint <= 0x10ffff; codePoint += 1) {
            if (codePoint >= 0xd800 && codePoint <= 0xdfff) {
                continue;
            }
            const char = String.fromCodePoint(codePoint);
            if (/^[\p{Lu}\p{Lt}]$/u.test(char) && !known.has(char)) {
                missing.push(`U+${codePoint.toString(16).toUpperCase()}`);
            }
        }
        expect(missing).toEqual([]);
    });
});

describe('codeKey', () => {
    it('zusammengesetzte Form wie gespeicherte Nummeren', () => {
        expect(codeKey('É-1')).toBe(codeKey('É-1'));
        expect(codeKey('É-1')).toBe('é-1');
    });

    it('Sigma am Wortende wie die Datenbank (je Zeichen)', () => {
        expect(codeKey('ΣΣ')).toBe('σσ');
    });
});

describe('isPossibleScanCode', () => {
    it.each([
        ['04006381333931', true],
        ['0104006381333931\u001d10ABC\u001e\u0004', true],
        ['mit Leerzeichen', true],
        ['x'.repeat(200), true],
        ['x'.repeat(201), false],
        ['', false],
        ['a\tb', false],
        ['a\nb', false],
        ['a\u0000b', false],
        ['a\u001fb', false],
        ['a\u0085b', false],
        ['a\u200bb', false],
        ['a\u2028b', false],
    ])('%j → %s', (code, expected) => {
        expect(isPossibleScanCode(code)).toBe(expected);
    });
});

describe('gestufte Suche (T28)', () => {
    const FLEET = [
        device(1, { identifier: 'M8' }),
        device(2, { identifier: 'M18' }),
        device(3, { identifier: 'M80' }),
        device(4, { identifier: 'HRT 8', device_type: { id: 3, name: 'Funkgerät' } }),
        device(5, { identifier: 'M3', serial_number: 'SN-0815', model: 'FPS 8000' }),
        device(6, {
            identifier: 'PA 1',
            device_type: { id: 2, name: 'Atemschutzgerät' },
            location: { id: 30, name: 'LF 8', path: 'LF 8' },
        }),
        device(7, { identifier: 'M008' }),
        device(8, { identifier: 'M9', scan_code: '4006381333938' }),
        device(9, { identifier: 'Lager-12/3' }),
    ];

    function tiers(query: string): [number, number][] {
        return rankDevices(FLEET, query).map((entry) => [entry.device.id, entry.tier]);
    }

    it.each([
        ['„8“: Zahl der Nummer genau, Seriennummer nie', '8', [1, 4, 7], [3, 6, 2]],
        ['„08“ wie „8“', '08', [1, 4, 7], [6]],
        ['„m8“: Nummer genau', 'm8', [1], [7, 3]],
        ['„m 8“ = „M8“', 'm 8', [1], [7, 3, 2, 6]],
        ['„m08“: ohne führende Nullen', 'm08', [1, 7], []],
        ['„hrt8“ ohne Leerzeichen', 'hrt8', [4], []],
        ['„m1“: Nummer beginnt damit', 'm1', [2], []],
        ['„maske 8“: Typ und Zahl der Nummer', 'maske 8', [1, 7], [2, 3]],
        ['„lf 8“: Standort LF 8, nicht HLF 20', 'lf 8', [6], [1, 2, 3, 4, 7]],
        ['„0815“: erst dann die Seriennummer', '0815', [5], []],
        ['Scan-Code genau', '4006381333938', [8], []],
        ['Zahl in einer Nummer mit Trennzeichen', '12', [9], []],
    ])('%s', (_label, query, hits, more) => {
        const result = searchDevices(FLEET, query);
        expect(result.hits.map((d) => d.id)).toEqual(hits);
        expect(result.more.map((d) => d.id)).toEqual(more);
        expect(result.total).toBe(hits.length);
        expect(result.moreTotal).toBe(more.length);
    });

    it('kurze Zahlen suchen in Stufe 6 nur in der Nummer', () => {
        // „81“ steckt nur in der Seriennummer SN-0815 von M3.
        expect(allIds('81', FLEET)).toEqual([]);
        // Ab drei Ziffern auch dort.
        expect(allIds('815', FLEET)).toEqual([5]);
    });

    it('Stufen in der Reihenfolge 1 bis 6', () => {
        expect(tiers('m8')).toEqual([
            [1, 1],
            [7, 2],
            [3, 3],
        ]);
        expect(tiers('8')).toEqual([
            [1, 2],
            [4, 2],
            [7, 2],
            [3, 3],
            [6, 4],
            [2, 5],
        ]);
        expect(tiers('0815')).toEqual([[5, 6]]);
    });
});
