import { describe, expect, it } from 'vitest';
import {
    conflictDeviceDetail,
    fieldChangesBody,
    postDevice,
    postDeviceFields,
    postInspectionDue,
    postReinstate,
    postRetire,
} from '../../src/api/devices';
import { respond } from './support/fetch';
import { deviceJson } from './support/devices';

/** Antwort mit allen Feldern wie DeviceJson::device. */
function fullJson(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return deviceJson(id, {
        procured_on: '2020-03-01',
        note: 'Zeile 1\nZeile 2',
        created_at: '2026-01-05T09:00:00Z',
        updated_at: '2026-10-01T08:00:00Z',
        ...overrides,
    });
}

describe('fieldChangesBody', () => {
    it('nur angegebene Felder, null leert', () => {
        expect(
            fieldChangesBody({
                identifier: 'M 7',
                scanCode: null,
                deviceTypeId: 3,
                serialNumber: 'SN',
                procuredOn: '2026-01-02',
                note: null,
            }),
        ).toEqual({
            identifier: 'M 7',
            scan_code: null,
            device_type_id: 3,
            serial_number: 'SN',
            procured_on: '2026-01-02',
            note: null,
        });
        expect(fieldChangesBody({})).toEqual({});
    });
});

describe('Aktionen des Admins', () => {
    it('POST devices/{id}/fields mit Version, Kommentar und den Feldern', async () => {
        const { fetch, requests } = respond(200, { device: fullJson(5, { version: 3 }) });
        const result = await postDeviceFields(
            5,
            {
                version: 2,
                comment: 'Typenschild',
                changes: { manufacturer: 'Dräger', model: null },
            },
            fetch,
        );
        expect(requests[0]?.url).toMatch(/\/api\/v1\/devices\/5\/fields$/);
        expect(requests[0]?.method).toBe('POST');
        expect(requests[0]?.body).toEqual({
            version: 2,
            comment: 'Typenschild',
            manufacturer: 'Dräger',
            model: null,
        });
        expect(result.ok && result.data.version).toBe(3);
        expect(result.ok && result.data.note).toBe('Zeile 1\nZeile 2');
    });

    it('POST devices mit Pflichtfeldern und nur den angegebenen optionalen', async () => {
        const { fetch, requests } = respond(201, { device: fullJson(9) });
        const result = await postDevice(
            { identifier: 'M 9', deviceTypeId: 1, locationId: 11, status: 'ready' },
            fetch,
        );
        expect(requests[0]?.url).toMatch(/\/api\/v1\/devices$/);
        expect(requests[0]?.method).toBe('POST');
        expect(requests[0]?.body).toEqual({
            identifier: 'M 9',
            device_type_id: 1,
            location_id: 11,
            status: 'ready',
        });
        expect(result.ok && result.data.id).toBe(9);

        const second = respond(201, { device: fullJson(10) });
        await postDevice(
            {
                identifier: 'M 10',
                deviceTypeId: 2,
                locationId: 20,
                status: 'defective',
                scanCode: 'ABC',
                serialNumber: 'SN',
                manufacturer: 'Dräger',
                model: 'FPS',
                procuredOn: '2020-01-02',
                nextInspectionDue: '2027-03-01',
                note: 'Zeile 1\nZeile 2',
                comment: 'Neu',
            },
            second.fetch,
        );
        expect(second.requests[0]?.body).toEqual({
            identifier: 'M 10',
            scan_code: 'ABC',
            device_type_id: 2,
            serial_number: 'SN',
            manufacturer: 'Dräger',
            model: 'FPS',
            procured_on: '2020-01-02',
            note: 'Zeile 1\nZeile 2',
            location_id: 20,
            status: 'defective',
            next_inspection_due: '2027-03-01',
            comment: 'Neu',
        });
    });

    it('POST devices/{id}/inspection-due sendet auch null (entfernen)', async () => {
        const { fetch, requests } = respond(200, { device: fullJson(5) });
        await postInspectionDue(5, { version: 4, nextInspectionDue: null }, fetch);
        expect(requests[0]?.url).toMatch(/\/devices\/5\/inspection-due$/);
        expect(requests[0]?.body).toEqual({ version: 4, next_inspection_due: null });
    });

    it('POST devices/{id}/retire', async () => {
        const { fetch, requests } = respond(200, {
            device: fullJson(5, { status: 'retired', status_label: 'ausgesondert' }),
        });
        const result = await postRetire(5, { version: 1, comment: 'defekt' }, fetch);
        expect(requests[0]?.url).toMatch(/\/devices\/5\/retire$/);
        expect(requests[0]?.body).toEqual({ version: 1, comment: 'defekt' });
        expect(result.ok && result.data.status).toBe('retired');
    });

    it('POST devices/{id}/reinstate nur mit geänderten Angaben', async () => {
        const { fetch, requests } = respond(200, { device: fullJson(5) });
        await postReinstate(5, { version: 6, status: 'maintenance' }, fetch);
        expect(requests[0]?.url).toMatch(/\/devices\/5\/reinstate$/);
        expect(requests[0]?.body).toEqual({ version: 6, status: 'maintenance' });

        const second = respond(200, { device: fullJson(5) });
        await postReinstate(
            5,
            {
                version: 6,
                status: 'ready',
                locationId: 20,
                identifier: 'M 8',
                scanCode: null,
                deviceTypeId: 2,
                comment: 'zurück',
            },
            second.fetch,
        );
        expect(second.requests[0]?.body).toEqual({
            version: 6,
            comment: 'zurück',
            status: 'ready',
            location_id: 20,
            identifier: 'M 8',
            scan_code: null,
            device_type_id: 2,
        });
    });

    it('Antwort ohne Felder des Details ist ungültig', async () => {
        const { fetch } = respond(200, { device: deviceJson(5) });
        const result = await postRetire(5, { version: 1 }, fetch);
        expect(!result.ok && result.error.code).toBe('invalid_response');
    });

    it('version_conflict: aktueller Stand mit allen Feldern', async () => {
        const { fetch } = respond(409, {
            error: 'version_conflict',
            message: 'Das Gerät wurde inzwischen geändert.',
            details: { device: fullJson(5, { version: 9 }) },
        });
        const result = await postDeviceFields(5, { version: 2, changes: { note: 'x' } }, fetch);
        expect(result.ok).toBe(false);
        const latest = result.ok ? null : conflictDeviceDetail(result.error.details);
        expect(latest?.version).toBe(9);
        expect(latest?.procuredOn).toBe('2020-03-01');
        expect(conflictDeviceDetail(null)).toBeNull();
    });
});
