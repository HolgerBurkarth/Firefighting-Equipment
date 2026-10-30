import { describe, expect, it } from 'vitest';
import {
    createLocation,
    fetchAllLocations,
    fetchLocations,
    parseLocationResponse,
    parseLocationTree,
    renameLocation,
    setLocationActive,
} from '../../src/api/locations';
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

describe('Verwaltung der Standorte', () => {
    const child = {
        id: 11,
        name: 'G1',
        kind: 'vehicle',
        kind_label: 'Fahrzeug',
        level: 2,
        parent_id: 10,
        active: false,
        device_count: 0,
    };

    it('fragt den ganzen Baum mit gesperrten', async () => {
        const { fetch, requests } = respond(200, { locations: [locationJson()] });
        const result = await fetchAllLocations(fetch);
        expect(result.ok).toBe(true);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/locations\?include_inactive=1$/);
    });

    it('liest die Antwort einer Änderung für Ebene 1 und Fächer', () => {
        expect(parseLocationResponse({ location: locationJson() })).toEqual({
            id: 10,
            name: 'HLF 20',
            level: 1,
            active: true,
        });
        expect(parseLocationResponse({ location: child })).toEqual({
            id: 11,
            name: 'G1',
            level: 2,
            active: false,
        });
        expect(parseLocationResponse({ location: { ...child, parent_id: null } })).toBeNull();
        expect(parseLocationResponse({ locations: [] })).toBeNull();
    });

    it('legt Standorte der Ebene 1 mit Art und Fächer mit parent_id an', async () => {
        const first = respond(201, { location: locationJson() });
        await createLocation({ name: ' HLF 20 ', kind: 'vehicle' }, first.fetch);
        expect(first.requests[0]).toMatchObject({
            method: 'POST',
            body: { name: ' HLF 20 ', kind: 'vehicle' },
        });
        expect(first.requests[0]?.url).toMatch(/\/api\/v1\/locations$/);

        const second = respond(201, { location: child });
        const result = await createLocation({ name: 'G1', parentId: 10 }, second.fetch);
        expect(result.ok).toBe(true);
        expect(second.requests[0]?.body).toEqual({ name: 'G1', parent_id: 10 });
    });

    it('benennt um, sperrt und entsperrt', async () => {
        const rename = respond(200, { location: child });
        await renameLocation(11, 'G 1', rename.fetch);
        expect(rename.requests[0]?.url).toMatch(/\/api\/v1\/locations\/11\/rename$/);
        expect(rename.requests[0]?.body).toEqual({ name: 'G 1' });

        const lock = respond(200, { location: child });
        await setLocationActive(11, false, lock.fetch);
        expect(lock.requests[0]?.url).toMatch(/\/api\/v1\/locations\/11\/lock$/);
        expect(lock.requests[0]?.body).toEqual({});

        const unlock = respond(200, { location: { ...child, active: true } });
        await setLocationActive(11, true, unlock.fetch);
        expect(unlock.requests[0]?.url).toMatch(/\/api\/v1\/locations\/11\/unlock$/);
    });

    it('meldet 409 mit details beim Sperren eines vollen Standorts', async () => {
        const { fetch } = respond(409, {
            error: 'rule_violation',
            message: 'Der Standort „G1“ ist nicht leer (3 Geräte).',
            details: { devices: 3, sublocations: 0 },
        });
        const result = await setLocationActive(11, false, fetch);
        expect(result.ok).toBe(false);
        expect(!result.ok && result.error.code).toBe('rule_violation');
        expect(!result.ok && result.error.details).toEqual({ devices: 3, sublocations: 0 });
    });
});
