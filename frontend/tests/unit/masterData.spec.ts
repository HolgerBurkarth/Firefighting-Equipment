import { describe, expect, it } from 'vitest';
import type { DeviceType } from '../../src/api/deviceTypes';
import type { Location, SubLocation } from '../../src/api/locations';
import {
    DEVICE_TYPE_NAME_MAX_LENGTH,
    deviceTypeRows,
    filterLocationTree,
    LOCATION_NAME_MAX_LENGTH,
    lockBlocker,
    lockedCount,
    nameProblem,
    sameName,
} from '../../src/data/masterData';

function sub(id: number, name: string, deviceCount = 0, active = true): SubLocation {
    return { id, name, kind: 'vehicle', active, deviceCount };
}

function location(
    id: number,
    name: string,
    children: readonly SubLocation[] = [],
    overrides: Partial<Location> = {},
): Location {
    const own = overrides.deviceCount ?? 0;
    return {
        id,
        name,
        kind: 'vehicle',
        kindLabel: 'Fahrzeug',
        active: true,
        deviceCount: own,
        deviceCountTotal: children.reduce((sum, child) => sum + child.deviceCount, own),
        children,
        ...overrides,
    };
}

describe('nameProblem (wie DeviceNameRules)', () => {
    it('Höchstlängen wie LocationName und DeviceTypeName', () => {
        expect(LOCATION_NAME_MAX_LENGTH).toBe(100);
        expect(DEVICE_TYPE_NAME_MAX_LENGTH).toBe(50);
    });

    it.each([
        ['leer', ''],
        ['nur Leerzeichen', '   '],
        ['nur Tab und Zeilenumbruch', '\t\n'],
    ])('verlangt einen Namen: %s', (_label, value) => {
        expect(nameProblem(value, 100)).toBe('Bitte einen Namen eingeben.');
    });

    it('zählt Zeichen nach dem Entfernen von Leerraum am Rand, nicht UTF-16-Einheiten', () => {
        expect(nameProblem(`  ${'a'.repeat(50)}  `, 50)).toBeNull();
        expect(nameProblem('🚒'.repeat(50), 50)).toBeNull();
        expect(nameProblem('a'.repeat(51), 50)).toBe('Höchstens 50 Zeichen (jetzt 51).');
    });

    it('lehnt Steuerzeichen im Namen ab, am Rand nicht (der Server entfernt sie)', () => {
        expect(nameProblem('HLF\n20', 100)).toBe('Keine Zeilenumbrüche oder Steuerzeichen.');
        expect(nameProblem('HLF\u007f20', 100)).toBe('Keine Zeilenumbrüche oder Steuerzeichen.');
        expect(nameProblem('\tHLF 20\r\n', 100)).toBeNull();
    });

    it('ist nicht strenger als PHP trim(): geschütztes Leerzeichen bleibt ein Name', () => {
        // PHP trim() entfernt nur Leerzeichen, Tab, LF, CR, NUL und VT.
        expect(nameProblem(' ', 100)).toBeNull();
    });
});

describe('sameName', () => {
    it('ohne Groß-/Kleinschreibung, Akzente und mit ß = ss', () => {
        expect(sameName('HLF 20', 'hlf 20')).toBe(true);
        expect(sameName('Straße', 'Strasse')).toBe(true);
        expect(sameName(' Lager ', 'Lager')).toBe(true);
        expect(sameName('Lager', 'Lager 2')).toBe(false);
    });
});

describe('lockBlocker', () => {
    it('leerer Standort lässt sich sperren', () => {
        expect(lockBlocker(location(10, 'HLF 20', [sub(11, 'G1', 0, false)]))).toBeNull();
        expect(lockBlocker(sub(11, 'G1'))).toBeNull();
    });

    it('nennt Geräte direkt dort und aktive Fächer', () => {
        expect(lockBlocker(sub(11, 'G1', 3))).toBe(
            'Sperren geht nur, wenn der Standort leer ist. Hier liegen noch 3 Geräte.',
        );
        expect(
            lockBlocker(
                location(10, 'HLF 20', [sub(11, 'G1'), sub(12, 'G2', 4)], { deviceCount: 1 }),
            ),
        ).toBe(
            'Sperren geht nur, wenn der Standort leer ist. Hier liegen noch 1 Gerät und 2 aktive Fächer.',
        );
        expect(lockBlocker(location(10, 'HLF 20', [sub(11, 'G1')]))).toBe(
            'Sperren geht nur, wenn der Standort leer ist. Hier liegen noch 1 aktives Fach.',
        );
    });
});

describe('filterLocationTree', () => {
    const tree: readonly Location[] = [
        location(10, 'HLF 20', [sub(11, 'G1', 6), sub(12, 'G2', 3), sub(13, 'G3', 0, false)]),
        location(20, 'LF 10'),
        location(30, 'Altes Lager', [], { active: false, kind: 'room', kindLabel: 'Raum/Lager' }),
    ];

    it('ohne Suchbegriff alle aktiven, gesperrte nur auf Wunsch', () => {
        const groups = filterLocationTree(tree, '', false);
        expect(groups.map((group) => group.location.id)).toEqual([10, 20]);
        expect(groups[0]?.children.map((child) => child.id)).toEqual([11, 12]);

        const all = filterLocationTree(tree, '', true);
        expect(all.map((group) => group.location.id)).toEqual([10, 20, 30]);
        expect(all[0]?.children.map((child) => child.id)).toEqual([11, 12, 13]);
    });

    it('passender Name der Ebene 1 zeigt alle Fächer, sonst nur passende', () => {
        expect(filterLocationTree(tree, 'hlf', false)[0]?.children).toHaveLength(2);
        const hits = filterLocationTree(tree, 'hlf g2', false);
        expect(hits.map((group) => group.location.id)).toEqual([10]);
        expect(hits[0]?.children.map((child) => child.id)).toEqual([12]);
        expect(filterLocationTree(tree, 'G1', false)[0]?.children.map((c) => c.id)).toEqual([11]);
    });

    it('kein Treffer', () => {
        expect(filterLocationTree(tree, 'drehleiter', true)).toEqual([]);
        // Gesperrte zählen ohne Wunsch nicht, auch nicht als Treffer.
        expect(filterLocationTree(tree, 'altes', false)).toEqual([]);
        expect(filterLocationTree(tree, 'g3', false)).toEqual([]);
    });

    it('zählt gesperrte Standorte und Fächer', () => {
        expect(lockedCount(tree)).toBe(2);
        expect(lockedCount([])).toBe(0);
    });
});

describe('deviceTypeRows', () => {
    const types: readonly DeviceType[] = [
        { id: 1, name: 'Funkgerät', active: true, deviceCount: 12 },
        { id: 2, name: 'atemschutzmaske', active: true, deviceCount: 40 },
        { id: 3, name: 'Wärmebildkamera', active: false, deviceCount: 2 },
        { id: 4, name: 'Atemschutzgerät', active: true, deviceCount: 12 },
        { id: 5, name: 'Typ 10', active: true, deviceCount: 0 },
        { id: 6, name: 'Typ 2', active: true, deviceCount: 0 },
    ];

    function ids(rows: readonly DeviceType[]): number[] {
        return rows.map((row) => row.id);
    }

    it('nach Name, natürlich und ohne Groß-/Kleinschreibung; deaktivierte nur auf Wunsch', () => {
        expect(ids(deviceTypeRows(types, '', false, 'name', 'asc'))).toEqual([4, 2, 1, 6, 5]);
        expect(ids(deviceTypeRows(types, '', true, 'name', 'desc'))).toEqual([3, 5, 6, 1, 2, 4]);
    });

    it('nach Gerätezahl, bei Gleichstand nach Name aufsteigend', () => {
        expect(ids(deviceTypeRows(types, '', false, 'devices', 'desc'))).toEqual([2, 4, 1, 6, 5]);
        expect(ids(deviceTypeRows(types, '', false, 'devices', 'asc'))).toEqual([6, 5, 4, 1, 2]);
    });

    it('filtert nach Wörtern im Namen (ohne Akzente)', () => {
        expect(ids(deviceTypeRows(types, 'atem', false, 'name', 'asc'))).toEqual([4, 2]);
        expect(ids(deviceTypeRows(types, 'warme', true, 'name', 'asc'))).toEqual([3]);
        expect(ids(deviceTypeRows(types, 'warme', false, 'name', 'asc'))).toEqual([]);
    });
});
