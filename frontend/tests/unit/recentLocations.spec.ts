import { describe, expect, it } from 'vitest';
import { parseAuthState, type AuthState } from '../../src/api/auth';
import type { Location } from '../../src/api/locations';
import {
    bookingLocationChoices,
    locationPaths,
    MAX_RECENT_LOCATIONS,
    recentLocations,
} from '../../src/data/recentLocations';
import { meBody } from './support/me';

function account(id: number): AuthState {
    const body = meBody('user');
    const state = parseAuthState({ ...body, user: { ...(body['user'] as object), id } });
    if (state === null) {
        throw new Error('Ungültige Sitzung');
    }
    return state;
}

function location(
    id: number,
    name: string,
    children: Location['children'] = [],
    active = true,
): Location {
    return {
        id,
        name,
        kind: 'vehicle',
        kindLabel: 'Fahrzeug',
        active,
        deviceCount: 0,
        deviceCountTotal: 0,
        children,
    };
}

function child(id: number, name: string, active = true): Location['children'][number] {
    return { id, name, kind: 'vehicle', active, deviceCount: 0 };
}

describe('Zuletzt benutzte Standorte', () => {
    it('höchstens 3, neueste zuerst, je Konto', () => {
        window.localStorage.clear();
        const anna = account(7);
        for (const id of [1, 2, 3, 4, 2]) {
            recentLocations.add(window.localStorage, anna, id);
        }
        expect(MAX_RECENT_LOCATIONS).toBe(3);
        expect(recentLocations.load(window.localStorage, anna)).toEqual([2, 4, 3]);
        expect(recentLocations.key(anna)).toBe('ff-recent-locations:test:7');
        expect(recentLocations.load(window.localStorage, account(8))).toEqual([]);
        recentLocations.clear(window.localStorage, anna);
        expect(recentLocations.load(window.localStorage, anna)).toEqual([]);
    });
});

describe('locationPaths', () => {
    it('Pfade wie im Backend, ohne gesperrte', () => {
        const paths = locationPaths([
            location(10, 'HLF 20', [child(11, 'G1'), child(12, 'G2', false)]),
            location(20, 'Lager'),
            location(30, 'Alt', [child(31, 'X')], false),
        ]);
        expect([...paths.values()]).toEqual([
            { id: 10, path: 'HLF 20' },
            { id: 11, path: 'HLF 20 / G1' },
            { id: 20, path: 'Lager' },
        ]);
    });
});

describe('bookingLocationChoices', () => {
    const paths = locationPaths([
        location(10, 'HLF 20', [child(11, 'G1'), child(12, 'G2')]),
        location(20, 'LF 10'),
        location(30, 'Lager'),
    ]);
    const current = { id: 11, path: 'HLF 20 / G1' };

    it('aktuell und genau EIN zuletzt benutztes Ziel (T2), ohne unbekannte', () => {
        expect(bookingLocationChoices(current, null, [999, 12, 20, 30], paths)).toEqual([
            { id: 11, path: 'HLF 20 / G1', hint: 'aktuell' },
            { id: 12, path: 'HLF 20 / G2', hint: 'zuletzt' },
        ]);
    });

    it('der aktuelle zählt nicht als zuletzt benutzt', () => {
        expect(bookingLocationChoices(current, null, [11, 20], paths)).toEqual([
            { id: 11, path: 'HLF 20 / G1', hint: 'aktuell' },
            { id: 20, path: 'LF 10', hint: 'zuletzt' },
        ]);
    });

    it('ein gewähltes, nicht angezeigtes Ziel erscheint mit „gewählt“', () => {
        expect(bookingLocationChoices(current, 20, [12, 20], paths)).toEqual([
            { id: 11, path: 'HLF 20 / G1', hint: 'aktuell' },
            { id: 20, path: 'LF 10', hint: 'gewählt' },
            { id: 12, path: 'HLF 20 / G2', hint: 'zuletzt' },
        ]);
        expect(bookingLocationChoices(current, 12, [12, 20], paths)).toEqual([
            { id: 11, path: 'HLF 20 / G1', hint: 'aktuell' },
            { id: 12, path: 'HLF 20 / G2', hint: 'zuletzt' },
        ]);
        expect(bookingLocationChoices(current, 11, [], paths)).toEqual([
            { id: 11, path: 'HLF 20 / G1', hint: 'aktuell' },
        ]);
    });
});
