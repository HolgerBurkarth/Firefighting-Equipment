import { describe, expect, it } from 'vitest';
import type { ApiResult } from '../../src/api/client';
import type { DevicePage, DevicePageQuery } from '../../src/api/devices';
import { ADMIN_DEVICE_PAGE_SIZE, AdminDeviceList } from '../../src/data/adminDeviceList';
import { DEFAULT_DEVICE_FILTER, type DeviceListFilter } from '../../src/data/deviceList';
import { devices, pagedSource } from './support/devices';

function filter(change: Partial<DeviceListFilter> = {}): DeviceListFilter {
    return { ...DEFAULT_DEVICE_FILTER, ...change };
}

const FAILED: ApiResult<DevicePage> = {
    ok: false,
    error: { code: 'network', message: null, status: null, retryAfterSeconds: null, details: null },
};

describe('AdminDeviceList', () => {
    it('lädt die erste Seite mit den Parametern des Filters, dann weitere', async () => {
        const source = pagedSource(devices(1, 120));
        const queries: DevicePageQuery[] = [];
        const list = new AdminDeviceList((query) => {
            queries.push(query);
            return source.fetchPage(query);
        });
        await list.show(filter({ statuses: ['defective'], locationId: 10 }));
        expect(list.state.devices).toHaveLength(ADMIN_DEVICE_PAGE_SIZE);
        expect(list.state.total).toBe(120);
        expect(queries[0]).toEqual({
            status: ['defective'],
            locationId: 10,
            includeSublocations: true,
            sort: 'identifier',
            dir: 'asc',
            limit: 50,
            offset: 0,
        });

        await list.more();
        await list.more();
        expect(list.state.devices.map((device) => device.id)).toEqual(
            Array.from({ length: 120 }, (_, i) => i + 1),
        );
        expect(source.calls.map((call) => call.offset)).toEqual([0, 50, 100]);
    });

    it('neuer Filter: Liste leer bis zur Antwort; eine ältere Antwort zählt nicht', async () => {
        const pending: ((result: ApiResult<DevicePage>) => void)[] = [];
        const list = new AdminDeviceList(
            () =>
                new Promise((resolve) => {
                    pending.push(resolve);
                }),
        );
        const page = (ids: number[]): ApiResult<DevicePage> => ({
            ok: true,
            status: 200,
            data: { devices: devices(ids[0] ?? 1, ids[1] ?? 1), total: 2, limit: 50, offset: 0 },
        });
        const first = list.show(filter());
        const second = list.show(filter({ q: 'M' }));
        expect(list.state.filter?.q).toBe('M');
        expect(list.state.total).toBeNull();
        // Die Antwort zum alten Filter wird verworfen, danach lädt die Liste für den neuen.
        pending[0]?.(page([1, 2]));
        await Promise.resolve();
        await Promise.resolve();
        expect(list.state.devices).toEqual([]);
        await expect.poll(() => pending.length).toBe(2);
        pending[1]?.(page([7, 8]));
        await Promise.all([first, second]);
        expect(list.state.devices.map((device) => device.id)).toEqual([7, 8]);
    });

    it('gleicher Filter frischt nur auf; Fehler behalten die Liste', async () => {
        let time = 1_000_000;
        const source = pagedSource(devices(1, 3));
        let fail = false;
        const list = new AdminDeviceList(
            (query) => (fail ? Promise.resolve(FAILED) : source.fetchPage(query)),
            () => time,
        );
        await list.show(filter({ q: 'M1' }));
        await list.show(filter({ q: ' M1 ' }));
        // Innerhalb von 5 s nach dem letzten Laden kein neues (Refresher, Anlass „open“).
        expect(source.calls).toHaveLength(1);

        time += 60_000;
        fail = true;
        await list.refresh('changed');
        expect(list.state.devices).toHaveLength(3);
        expect(list.state.error?.code).toBe('network');
    });

    it('auffrischen lädt so viele wie schon geladen, höchstens 200; Leeren vergisst alles', async () => {
        const source = pagedSource(devices(1, 300));
        const list = new AdminDeviceList(source.fetchPage);
        await list.refresh('visible');
        expect(source.calls).toHaveLength(0);

        await list.show(filter());
        await list.more();
        await list.more();
        await list.refresh('changed');
        expect(source.calls.at(-1)).toEqual({ limit: 150, offset: 0 });
        expect(list.state.devices).toHaveLength(150);

        list.clear();
        expect(list.state).toMatchObject({ filter: null, devices: [], total: null });
    });
});
