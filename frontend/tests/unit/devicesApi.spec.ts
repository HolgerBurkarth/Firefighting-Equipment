import { describe, expect, it } from 'vitest';
import {
    conflictDevice,
    fetchDevicePage,
    parseBookingResponse,
    postDeviceState,
    parseDeviceListItem,
    parseDevicePage,
    DEVICE_PAGE_LIMIT,
} from '../../src/api/devices';
import { CLIENT_API_HEADER } from '../../src/api/client';
import { respond } from './support/fetch';
import { deviceJson } from './support/devices';

describe('parseDeviceListItem', () => {
    it('liest alle Felder', () => {
        const parsed = parseDeviceListItem(
            deviceJson(5, {
                identifier: 'PA 3',
                scan_code: '0104006381333931\u001d21ABC',
                status: 'defective',
                status_label: 'defekt',
                next_inspection_due: '2026-12-01',
                version: 4,
                serial_number: 'SN-1',
                manufacturer: 'Dräger',
                model: 'PSS 5000',
                state_changed_at: '2026-10-12T08:15:07Z',
            }),
        );
        expect(parsed).toEqual({
            id: 5,
            identifier: 'PA 3',
            scanCode: '0104006381333931\u001d21ABC',
            deviceType: { id: 1, name: 'Atemschutzmaske' },
            location: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
            status: 'defective',
            statusLabel: 'defekt',
            nextInspectionDue: '2026-12-01',
            version: 4,
            serialNumber: 'SN-1',
            manufacturer: 'Dräger',
            model: 'PSS 5000',
            stateChangedAt: '2026-10-12T08:15:07Z',
        });
    });

    it('state_changed_at fehlt oder ist null: null', () => {
        expect(
            parseDeviceListItem(deviceJson(5, { state_changed_at: undefined }))?.stateChangedAt,
        ).toBeNull();
        expect(
            parseDeviceListItem(deviceJson(5, { state_changed_at: null }))?.stateChangedAt,
        ).toBeNull();
    });

    it('ignoriert zusätzliche Felder (Antworten mit allen Feldern)', () => {
        const parsed = parseDeviceListItem(deviceJson(5, { note: 'x', procured_on: null }));
        expect(parsed?.id).toBe(5);
        expect(parsed).not.toHaveProperty('note');
    });

    it.each([
        ['id 0', { id: 0 }],
        ['id als Text', { id: '5' }],
        ['Kennung fehlt', { identifier: undefined }],
        ['unbekannter Status', { status: 'broken' }],
        ['Standort ohne Pfad', { location: { id: 11, name: 'G1' } }],
        ['Gerätetyp fehlt', { device_type: null }],
        ['Version 0', { version: 0 }],
        ['Seriennummer fehlt', { serial_number: undefined }],
        ['Hersteller als Zahl', { manufacturer: 5 }],
        ['Scan-Code fehlt', { scan_code: undefined }],
        ['Änderungszeit ohne Zeitzone', { state_changed_at: '2026-10-12T08:15:07' }],
        ['Änderungszeit kein Datum', { state_changed_at: '2026-13-45T99:00:00Z' }],
        ['Änderungszeit als Zahl', { state_changed_at: 1760256907 }],
    ])('lehnt ab: %s', (_label, overrides) => {
        expect(parseDeviceListItem(deviceJson(5, overrides))).toBeNull();
    });
});

describe('parseDevicePage', () => {
    it('liest Liste und Seitenangaben', () => {
        const page = parseDevicePage({
            devices: [deviceJson(1), deviceJson(2)],
            total: 7,
            limit: 2,
            offset: 4,
        });
        expect(page?.devices.map((d) => d.id)).toEqual([1, 2]);
        expect([page?.total, page?.limit, page?.offset]).toEqual([7, 2, 4]);
    });

    it('ein ungültiges Gerät macht die ganze Antwort ungültig', () => {
        expect(
            parseDevicePage({
                devices: [deviceJson(1), deviceJson(2, { status: 'x' })],
                total: 2,
                limit: 200,
                offset: 0,
            }),
        ).toBeNull();
    });

    it.each([
        { devices: [], total: -1, limit: 200, offset: 0 },
        { devices: [], total: 0, limit: 0, offset: 0 },
        { devices: {}, total: 0, limit: 200, offset: 0 },
        { total: 0, limit: 200, offset: 0 },
    ])('lehnt ungültige Seitenangaben ab (%#)', (json) => {
        expect(parseDevicePage(json)).toBeNull();
    });
});

describe('fetchDevicePage', () => {
    it('fragt GET devices mit limit, offset und Vertragsnummer', async () => {
        const { fetch, requests } = respond(200, {
            devices: [deviceJson(1)],
            total: 1,
            limit: DEVICE_PAGE_LIMIT,
            offset: 0,
        });
        const result = await fetchDevicePage({ limit: DEVICE_PAGE_LIMIT, offset: 0 }, fetch);
        expect(result.ok).toBe(true);
        expect(requests[0]?.method).toBe('GET');
        expect(requests[0]?.url).toMatch(
            /\/firefighting\/test\/api\/v1\/devices\?limit=200&offset=0$/,
        );
        expect(requests[0]?.headers[CLIENT_API_HEADER]).toBe('1');
    });

    it('kodiert den Suchbegriff', async () => {
        const { fetch, requests } = respond(200, { devices: [], total: 0, limit: 50, offset: 0 });
        await fetchDevicePage({ limit: 50, offset: 0, q: 'HLF 20 & Co' }, fetch);
        expect(new URL(requests[0]?.url ?? '').searchParams.get('q')).toBe('HLF 20 & Co');
    });

    it('unerwartete Antwort → invalid_response', async () => {
        const { fetch } = respond(200, { devices: 'x' });
        const result = await fetchDevicePage({ limit: 200, offset: 0 }, fetch);
        expect(result.ok ? null : result.error.code).toBe('invalid_response');
    });
});

describe('Buchung (POST devices/{id}/state)', () => {
    const RESULT = {
        outcome: 'applied',
        outcome_label: 'übernommen',
        reason: null,
        reason_label: null,
        message: 'Übernommen. „M5“ ist jetzt: HLF 20 / G2, defekt.',
        stale_confirmed: false,
        client_ref: '3f2b8c1e-5d4a-4b6f-9c2d-1a2b3c4d5e6f',
        replayed: false,
        notice_id: null,
    };

    it('sendet nur die geänderten Felder mit client_ref', async () => {
        const { fetch, requests } = respond(200, {
            device: deviceJson(5, { status: 'defective', status_label: 'defekt', version: 3 }),
            result: RESULT,
        });
        const result = await postDeviceState(
            5,
            { version: 2, status: 'defective', comment: 'Ventil undicht', clientRef: 'ref-1' },
            fetch,
        );
        expect(requests).toHaveLength(1);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/devices\/5\/state$/);
        expect(requests[0]?.method).toBe('POST');
        expect(requests[0]?.body).toEqual({
            version: 2,
            status: 'defective',
            comment: 'Ventil undicht',
            client_ref: 'ref-1',
        });
        expect(result.ok && result.data.device.version).toBe(3);
        expect(result.ok && result.data.result).toEqual({
            outcome: 'applied',
            outcomeLabel: 'übernommen',
            reason: null,
            reasonLabel: null,
            message: RESULT.message,
            staleConfirmed: false,
            clientRef: RESULT.client_ref,
            replayed: false,
            noticeId: null,
        });
    });

    it('Standort als location_id', async () => {
        const { fetch, requests } = respond(200, { device: deviceJson(5), result: RESULT });
        await postDeviceState(5, { version: 1, locationId: 12, clientRef: 'ref-2' }, fetch);
        expect(requests[0]?.body).toEqual({ version: 1, location_id: 12, client_ref: 'ref-2' });
    });

    it.each([
        ['unbekanntes Ergebnis', { outcome: 'done' }],
        ['Meldung fehlt', { message: undefined }],
        ['replayed als Text', { replayed: 'false' }],
        ['notice_id 0', { notice_id: 0 }],
        ['Grund als Zahl', { reason: 1 }],
    ])('lehnt ab: %s', (_label, overrides) => {
        expect(
            parseBookingResponse({ device: deviceJson(5), result: { ...RESULT, ...overrides } }),
        ).toBeNull();
    });

    it('lehnt ein ungültiges Gerät ab', () => {
        expect(parseBookingResponse({ device: { id: 5 }, result: RESULT })).toBeNull();
    });

    it('Konflikt: aktueller Stand aus details.device', () => {
        expect(conflictDevice({ device: deviceJson(5, { version: 7 }) })?.version).toBe(7);
        expect(conflictDevice({ device: null })).toBeNull();
        expect(conflictDevice(null)).toBeNull();
    });
});
