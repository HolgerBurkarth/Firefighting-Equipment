import { describe, expect, it } from 'vitest';
import {
    fetchDevicePage,
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
        });
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
