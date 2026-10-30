import { describe, expect, it } from 'vitest';
import { deviceListSearchParams } from '../../src/api/devices';
import {
    DEFAULT_DEVICE_FILTER,
    deviceCountLabel,
    deviceListParams,
    dueState,
    filterFromQuery,
    filterKey,
    filterToQuery,
    hasFilters,
    type DeviceListFilter,
} from '../../src/data/deviceList';

function filter(change: Partial<DeviceListFilter> = {}): DeviceListFilter {
    return { ...DEFAULT_DEVICE_FILTER, ...change };
}

describe('Filter in der Adresse', () => {
    it('ohne Parameter der Standard', () => {
        expect(filterFromQuery({})).toEqual(DEFAULT_DEVICE_FILTER);
        expect(filterToQuery(DEFAULT_DEVICE_FILTER)).toEqual({});
    });

    it('liest alle Parameter und schreibt sie wieder gleich', () => {
        const query = {
            suche: 'M1',
            typ: '3',
            standort: '10',
            status: 'maintenance,defective',
            ausgesonderte: '1',
            sortierung: 'next_inspection_due',
            richtung: 'desc',
        };
        const parsed = filterFromQuery(query);
        expect(parsed).toEqual({
            q: 'M1',
            deviceTypeId: 3,
            locationId: 10,
            statuses: ['maintenance', 'defective'],
            showRetired: true,
            sort: 'next_inspection_due',
            dir: 'desc',
        });
        expect(filterToQuery(parsed)).toEqual(query);
    });

    it('Unbekanntes fällt still auf den Standard zurück', () => {
        expect(
            filterFromQuery({
                typ: '012',
                standort: 'abc',
                status: 'retired,kaputt,defective,defective',
                ausgesonderte: 'ja',
                sortierung: 'serial_number',
                richtung: 'DESC',
            }),
        ).toEqual(filter({ statuses: ['defective'] }));
    });

    it('Status in fester Reihenfolge, nur der erste Wert eines Parameters', () => {
        expect(filterFromQuery({ status: 'defective,ready' }).statuses).toEqual([
            'ready',
            'defective',
        ]);
        expect(filterFromQuery({ typ: ['4', '5'] }).deviceTypeId).toBe(4);
        expect(filterFromQuery({ suche: 'x'.repeat(150) }).q).toHaveLength(100);
    });

    it('leere Suche steht nicht in der Adresse; gleicher Filter bei Leerraum am Rand', () => {
        expect(filterToQuery(filter({ q: '   ' }))).toEqual({});
        expect(filterKey(filter({ q: ' M1 ' }))).toBe(filterKey(filter({ q: 'M1' })));
        expect(filterKey(filter({ dir: 'desc' }))).not.toBe(filterKey(filter()));
    });

    it('Filter gesetzt (die Sortierung zählt nicht)', () => {
        expect(hasFilters(filter({ sort: 'status', dir: 'desc' }))).toBe(false);
        expect(hasFilters(filter({ showRetired: true }))).toBe(true);
        expect(hasFilters(filter({ q: ' ' }))).toBe(false);
    });
});

describe('Parameter für GET devices und den Export', () => {
    function search(change: Partial<DeviceListFilter>): string {
        return deviceListSearchParams(deviceListParams(filter(change))).toString();
    }

    it('Standard: nur die Sortierung', () => {
        expect(search({})).toBe('sort=identifier&dir=asc');
    });

    it('Standort immer mit Fächern, Suche getrimmt', () => {
        expect(search({ q: ' M1 ', deviceTypeId: 3, locationId: 10 })).toBe(
            'q=M1&device_type_id=3&location_id=10&include_sublocations=1&sort=identifier&dir=asc',
        );
    });

    it('Ausgesonderte: ohne Status include_retired, mit Status als weiterer Status', () => {
        expect(search({ showRetired: true })).toBe('include_retired=1&sort=identifier&dir=asc');
        expect(search({ showRetired: true, statuses: ['defective'] })).toBe(
            'status=defective%2Cretired&sort=identifier&dir=asc',
        );
        expect(search({ statuses: ['ready', 'maintenance'] })).toBe(
            'status=ready%2Cmaintenance&sort=identifier&dir=asc',
        );
    });
});

describe('dueState', () => {
    it('überfällig vor heute, bald fällig bis heute + 30 Tage', () => {
        expect(dueState('2026-10-29', '2026-10-30', 'ready')).toBe('overdue');
        expect(dueState('2026-10-30', '2026-10-30', 'ready')).toBe('soon');
        expect(dueState('2026-11-29', '2026-10-30', 'ready')).toBe('soon');
        expect(dueState('2026-11-30', '2026-10-30', 'ready')).toBeNull();
        expect(dueState(null, '2026-10-30', 'ready')).toBeNull();
    });

    it('ausgesonderte nie', () => {
        expect(dueState('2020-01-01', '2026-10-30', 'retired')).toBeNull();
    });
});

describe('deviceCountLabel', () => {
    it('Anzahl, mit Filter „gefunden“', () => {
        expect(deviceCountLabel(45, false)).toBe('45 Geräte');
        expect(deviceCountLabel(1, false)).toBe('1 Gerät');
        expect(deviceCountLabel(12, true)).toBe('12 Geräte gefunden');
    });
});
