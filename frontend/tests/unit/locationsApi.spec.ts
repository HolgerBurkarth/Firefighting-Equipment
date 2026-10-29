import { describe, expect, it } from 'vitest';
import { fetchLocations, parseLocationTree } from '../../src/api/locations';
import { respond } from './support/fetch';

/** Standort wie LocationJson in PHP (Ebene 1 mit Unterstandorten). */
function locationJson(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 10,
        name: 'HLF 20',
        kind: 'vehicle',
        kind_label: 'Fahrzeug',
        level: 1,
        parent_id: null,
        active: true,
        device_count: 1,
        device_count_total: 4,
        children: [
            {
                id: 11,
                name: 'G1',
                kind: 'vehicle',
                kind_label: 'Fahrzeug',
                level: 2,
                parent_id: 10,
                active: true,
                device_count: 3,
                created_at: '2026-10-01T08:00:00Z',
                updated_at: '2026-10-01T08:00:00Z',
            },
        ],
        created_at: '2026-10-01T08:00:00Z',
        updated_at: '2026-10-01T08:00:00Z',
        ...overrides,
    };
}

describe('parseLocationTree', () => {
    it('liest Standorte mit Unterstandorten', () => {
        expect(parseLocationTree({ locations: [locationJson()] })).toEqual([
            {
                id: 10,
                name: 'HLF 20',
                kind: 'vehicle',
                kindLabel: 'Fahrzeug',
                active: true,
                deviceCount: 1,
                deviceCountTotal: 4,
                children: [{ id: 11, name: 'G1', kind: 'vehicle', active: true, deviceCount: 3 }],
            },
        ]);
    });

    it('Raum ohne Unterstandorte', () => {
        const tree = parseLocationTree({
            locations: [
                locationJson({ id: 20, kind: 'room', kind_label: 'Raum/Lager', children: [] }),
            ],
        });
        expect(tree?.[0]?.children).toEqual([]);
    });

    it.each([
        ['Ebene 2 oben', locationJson({ level: 2 })],
        ['Ebene 1 mit parent_id', locationJson({ parent_id: 3 })],
        ['unbekannte Art', locationJson({ kind: 'boat' })],
        ['ohne device_count_total', locationJson({ device_count_total: undefined })],
        ['ohne children', locationJson({ children: undefined })],
        [
            'Unterstandort anderer Art',
            locationJson({
                children: [
                    {
                        id: 11,
                        name: 'G1',
                        kind: 'room',
                        level: 2,
                        parent_id: 10,
                        active: true,
                        device_count: 0,
                    },
                ],
            }),
        ],
        [
            'Unterstandort eines anderen Standorts',
            locationJson({
                children: [
                    {
                        id: 11,
                        name: 'G1',
                        kind: 'vehicle',
                        level: 2,
                        parent_id: 99,
                        active: true,
                        device_count: 0,
                    },
                ],
            }),
        ],
    ])('lehnt ab: %s', (_label, json) => {
        expect(parseLocationTree({ locations: [json] })).toBeNull();
    });

    it('lehnt eine Antwort ohne Liste ab', () => {
        expect(parseLocationTree({ location: [] })).toBeNull();
    });
});

describe('fetchLocations', () => {
    it('fragt GET locations ohne gesperrte', async () => {
        const { fetch, requests } = respond(200, { locations: [locationJson()] });
        const result = await fetchLocations(fetch);
        expect(result.ok).toBe(true);
        expect(requests[0]?.url).toMatch(/\/firefighting\/test\/api\/v1\/locations$/);
    });
});
