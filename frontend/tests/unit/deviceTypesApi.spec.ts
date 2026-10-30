import { describe, expect, it } from 'vitest';
import {
    createDeviceType,
    fetchAllDeviceTypes,
    parseDeviceTypeList,
    parseDeviceTypeResponse,
    renameDeviceType,
    setDeviceTypeActive,
} from '../../src/api/deviceTypes';
import { respond } from './support/fetch';

/** Gerätetyp wie DeviceTypeJson in PHP. */
function typeJson(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 3,
        name: 'Funkgerät',
        active: true,
        device_count: 12,
        created_at: '2026-10-01T08:00:00Z',
        updated_at: '2026-10-01T08:00:00Z',
        ...overrides,
    };
}

describe('parseDeviceTypeList', () => {
    it('liest Gerätetypen', () => {
        expect(
            parseDeviceTypeList({ device_types: [typeJson(), typeJson({ id: 4, active: false })] }),
        ).toEqual([
            { id: 3, name: 'Funkgerät', active: true, deviceCount: 12 },
            { id: 4, name: 'Funkgerät', active: false, deviceCount: 12 },
        ]);
    });

    it.each([
        ['ID 0', typeJson({ id: 0 })],
        ['Name fehlt', typeJson({ name: null })],
        ['active als Zahl', typeJson({ active: 1 })],
        ['negative Anzahl', typeJson({ device_count: -1 })],
        ['Anzahl als Text', typeJson({ device_count: '12' })],
    ])('lehnt ab: %s', (_label, json) => {
        expect(parseDeviceTypeList({ device_types: [json] })).toBeNull();
    });

    it('lehnt eine Antwort ohne Liste ab', () => {
        expect(parseDeviceTypeList({ device_type: [] })).toBeNull();
        expect(parseDeviceTypeResponse({ device_types: [typeJson()] })).toBeNull();
    });
});

describe('Anfragen', () => {
    it('fragt alle Gerätetypen samt deaktivierten', async () => {
        const { fetch, requests } = respond(200, { device_types: [typeJson()] });
        const result = await fetchAllDeviceTypes(fetch);
        expect(result.ok).toBe(true);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/device-types\?include_inactive=1$/);
    });

    it('legt an und benennt um (Name unverändert)', async () => {
        const create = respond(201, { device_type: typeJson() });
        const created = await createDeviceType(' Funkgerät ', create.fetch);
        expect(created.ok && created.data.id).toBe(3);
        expect(create.requests[0]).toMatchObject({
            method: 'POST',
            body: { name: ' Funkgerät ' },
        });
        expect(create.requests[0]?.url).toMatch(/\/api\/v1\/device-types$/);

        const rename = respond(200, { device_type: typeJson({ name: 'Handfunkgerät' }) });
        await renameDeviceType(3, 'Handfunkgerät', rename.fetch);
        expect(rename.requests[0]?.url).toMatch(/\/api\/v1\/device-types\/3\/rename$/);
        expect(rename.requests[0]?.body).toEqual({ name: 'Handfunkgerät' });
    });

    it('deaktiviert und aktiviert', async () => {
        const off = respond(200, { device_type: typeJson({ active: false }) });
        await setDeviceTypeActive(3, false, off.fetch);
        expect(off.requests[0]?.url).toMatch(/\/api\/v1\/device-types\/3\/deactivate$/);
        expect(off.requests[0]?.body).toEqual({});

        const on = respond(200, { device_type: typeJson() });
        await setDeviceTypeActive(3, true, on.fetch);
        expect(on.requests[0]?.url).toMatch(/\/api\/v1\/device-types\/3\/activate$/);
    });

    it('meldet name_taken', async () => {
        const { fetch } = respond(409, {
            error: 'name_taken',
            message: 'Es gibt bereits einen Gerätetyp „Funkgerät“.',
        });
        const result = await createDeviceType('funkgerät', fetch);
        expect(!result.ok && result.error.code).toBe('name_taken');
    });
});
