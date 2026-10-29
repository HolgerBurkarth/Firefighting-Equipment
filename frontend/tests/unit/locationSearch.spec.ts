import { describe, expect, it } from 'vitest';
import type { Location, LocationKind } from '../../src/api/locations';
import {
    deviceCountText,
    groupOf,
    locationGroups,
    searchLocations,
} from '../../src/data/locationSearch';

function location(
    id: number,
    name: string,
    children: { id: number; name: string; count?: number; active?: boolean }[] = [],
    options: { kind?: LocationKind; total?: number; active?: boolean } = {},
): Location {
    const kind = options.kind ?? 'vehicle';
    return {
        id,
        name,
        kind,
        kindLabel: 'x',
        active: options.active ?? true,
        deviceCount: 0,
        deviceCountTotal: options.total ?? 0,
        children: children.map((child) => ({
            id: child.id,
            name: child.name,
            kind,
            active: child.active ?? true,
            deviceCount: child.count ?? 0,
        })),
    };
}

const TREE: readonly Location[] = [
    location(
        10,
        'HLF 20',
        [
            { id: 11, name: 'G1', count: 6 },
            { id: 12, name: 'G2', count: 1 },
            { id: 13, name: 'Alt', active: false },
        ],
        { total: 9 },
    ),
    location(20, 'LF 10', [{ id: 21, name: 'G1' }]),
    location(30, 'Lager Gerätehaus', [], { kind: 'room', total: 12 }),
    location(40, 'Gesperrt', [], { active: false }),
    location(50, 'Straßenmeisterei', [{ id: 51, name: 'Halle' }], { kind: 'external' }),
];

describe('locationGroups', () => {
    it('Gruppen ohne gesperrte; mit Fächern zuerst der ganze Standort', () => {
        const groups = locationGroups(TREE);
        expect(groups.map((g) => g.entry.label)).toEqual([
            'HLF 20',
            'LF 10',
            'Lager Gerätehaus',
            'Straßenmeisterei',
        ]);
        const hlf = groups[0];
        expect(hlf?.entry.deviceCount).toBe(9);
        expect(hlf?.children.map((c) => [c.id, c.label, c.path, c.whole, c.deviceCount])).toEqual([
            [10, 'Ganzes Fahrzeug, ohne Fach', 'HLF 20', true, null],
            [11, 'G1', 'HLF 20 / G1', false, 6],
            [12, 'G2', 'HLF 20 / G2', false, 1],
        ]);
        expect(groups[2]?.children).toEqual([]);
        expect(groups[3]?.children[0]?.label).toBe('Ganzer Standort, ohne Fach');
    });
});

describe('searchLocations', () => {
    it.each([
        ['', []],
        ['hlf', ['HLF 20', 'HLF 20 / G1', 'HLF 20 / G2']],
        ['hlf g1', ['HLF 20 / G1']],
        ['g1', ['HLF 20 / G1', 'LF 10 / G1']],
        ['lf', ['HLF 20', 'HLF 20 / G1', 'HLF 20 / G2', 'LF 10', 'LF 10 / G1']],
        ['geratehaus', ['Lager Gerätehaus']],
        ['strasse', ['Straßenmeisterei', 'Straßenmeisterei / Halle']],
        ['alt', []],
        ['gesperrt', []],
        ['xyz', []],
    ])('%j → %j', (query, paths) => {
        expect(searchLocations(TREE, query).map((hit) => hit.path)).toEqual(paths);
    });

    it('Fächer mit ihrem Fahrzeug', () => {
        const [hit] = searchLocations(TREE, 'hlf g2');
        expect(hit).toMatchObject({ id: 12, label: 'G2', parentName: 'HLF 20', level: 2 });
    });
});

describe('Hilfen', () => {
    it('groupOf: Standort selbst oder sein übergeordneter', () => {
        expect(groupOf(TREE, 10)).toBe(10);
        expect(groupOf(TREE, 12)).toBe(10);
        expect(groupOf(TREE, 30)).toBe(30);
        expect(groupOf(TREE, 999)).toBeNull();
    });

    it('Anzahl der Geräte', () => {
        expect(deviceCountText(0)).toBe('0 Geräte');
        expect(deviceCountText(1)).toBe('1 Gerät');
        expect(deviceCountText(12)).toBe('12 Geräte');
    });
});
