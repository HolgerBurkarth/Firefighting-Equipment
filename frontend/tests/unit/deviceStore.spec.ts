import { describe, expect, it } from 'vitest';
import type { ApiResult } from '../../src/api/client';
import type { DevicePage, DevicePageQuery } from '../../src/api/devices';
import { DeviceStore, MAX_LOCAL_DEVICES } from '../../src/data/deviceStore';
import { device, devices, pagedSource } from './support/devices';

const NETWORK: ApiResult<never> = {
    ok: false,
    error: { code: 'network', message: null, status: null, retryAfterSeconds: null, details: null },
};

describe('DeviceStore: Laden in Blöcken', () => {
    it('vor dem ersten Laden leer und ohne Modus', () => {
        const store = new DeviceStore(pagedSource([]).fetchPage);
        expect(store.state).toEqual({
            mode: null,
            devices: [],
            total: null,
            loading: false,
            error: null,
        });
    });

    it.each([
        [0, [0]],
        [1, [0]],
        [200, [0]],
        [201, [0, 200]],
        [450, [0, 200, 400]],
    ])('%i Geräte → Blöcke ab %j', async (count, offsets) => {
        const source = pagedSource(devices(1, count));
        const store = new DeviceStore(source.fetchPage);
        await store.refresh('open');
        expect(source.calls.map((call) => call.offset)).toEqual(offsets);
        expect(source.calls.every((call) => call.limit === 200)).toBe(true);
        expect(store.state.mode).toBe('local');
        expect(store.state.total).toBe(count);
        expect(store.state.devices.map((d) => d.id)).toEqual(devices(1, count).map((d) => d.id));
        expect(store.state.loading).toBe(false);
    });

    it(`genau ${MAX_LOCAL_DEVICES} Geräte → noch lokal (10 Blöcke)`, async () => {
        const source = pagedSource(devices(1, MAX_LOCAL_DEVICES));
        const store = new DeviceStore(source.fetchPage);
        await store.refresh('open');
        expect(source.calls).toHaveLength(10);
        expect(store.state.mode).toBe('local');
        expect(store.state.devices).toHaveLength(MAX_LOCAL_DEVICES);
    });

    it(`${MAX_LOCAL_DEVICES + 1} Geräte → Suche auf dem Server, nur ein Aufruf`, async () => {
        const source = pagedSource(devices(1, MAX_LOCAL_DEVICES + 1));
        const store = new DeviceStore(source.fetchPage);
        await store.refresh('open');
        expect(source.calls).toHaveLength(1);
        expect(store.state).toMatchObject({
            mode: 'server',
            devices: [],
            total: 2001,
            error: null,
        });
    });

    it('wechselt zurück zu lokal, wenn der Bestand wieder klein ist', async () => {
        const source = pagedSource(devices(1, MAX_LOCAL_DEVICES + 1));
        const store = new DeviceStore(source.fetchPage);
        await store.refresh('open');
        source.set(devices(1, 3));
        await store.refresh('changed');
        expect(store.state).toMatchObject({ mode: 'local', total: 3 });
    });

    it('Bestand ändert sich während des Ladens → einmal neu laden', async () => {
        const source = pagedSource(devices(1, 300));
        let first = true;
        const fetchPage = async (query: DevicePageQuery): Promise<ApiResult<DevicePage>> => {
            const result = await source.fetchPage(query);
            // Nach dem ersten Block kommt ein neues Gerät dazu (verschiebt die Seiten).
            if (first) {
                first = false;
                source.set([device(1000), ...devices(1, 300)]);
            }
            return result;
        };
        const store = new DeviceStore(fetchPage);
        await store.refresh('open');
        expect(source.calls.map((call) => call.offset)).toEqual([0, 200, 0, 200]);
        expect(store.state.total).toBe(301);
        expect(new Set(store.state.devices.map((d) => d.id)).size).toBe(301);
    });

    it('doppelte Geräte über Blockgrenzen erscheinen einmal', async () => {
        const all = devices(1, 250);
        const fetchPage = (query: DevicePageQuery): Promise<ApiResult<DevicePage>> => {
            // Zweiter Block beginnt ein Gerät zu früh (z. B. eines wurde inzwischen ausgesondert).
            const start = query.offset === 0 ? 0 : query.offset - 1;
            const page = all.slice(start, query.offset + query.limit);
            return Promise.resolve({
                ok: true,
                status: 200,
                data: { devices: page, total: 250, limit: query.limit, offset: query.offset },
            });
        };
        const store = new DeviceStore(fetchPage);
        await store.refresh('open');
        expect(store.state.devices).toHaveLength(250);
    });

    it('Fehler mitten im Laden: bisherige Liste bleibt, Fehler steht im Zustand', async () => {
        const source = pagedSource(devices(1, 3));
        let fail = false;
        const fetchPage = (query: DevicePageQuery): Promise<ApiResult<DevicePage>> =>
            fail && query.offset === 200 ? Promise.resolve(NETWORK) : source.fetchPage(query);
        const store = new DeviceStore(fetchPage);
        await store.refresh('open');

        source.set(devices(1, 300));
        fail = true;
        await store.refresh('changed');
        expect(store.state.devices.map((d) => d.id)).toEqual([1, 2, 3]);
        expect(store.state.error?.code).toBe('network');
        expect(store.state.loading).toBe(false);

        fail = false;
        await store.refresh('changed');
        expect(store.state.devices).toHaveLength(300);
        expect(store.state.error).toBeNull();
    });

    it('zeigt das Laden an', async () => {
        let release: (() => void) | null = null;
        const source = pagedSource(devices(1, 2));
        const store = new DeviceStore(
            (query) =>
                new Promise((resolve) => {
                    release = () => {
                        void source.fetchPage(query).then(resolve);
                    };
                }),
        );
        const loading = store.refresh('open');
        expect(store.state.loading).toBe(true);
        (release as (() => void) | null)?.();
        await loading;
        expect(store.state.loading).toBe(false);
    });

    it('clear während des Ladens verwirft das Ergebnis', async () => {
        let release: (() => void) | null = null;
        const source = pagedSource(devices(1, 2));
        const store = new DeviceStore(
            (query) =>
                new Promise((resolve) => {
                    release = () => {
                        void source.fetchPage(query).then(resolve);
                    };
                }),
        );
        const loading = store.refresh('open');
        store.clear();
        (release as (() => void) | null)?.();
        await loading;
        expect(store.state).toMatchObject({ mode: null, devices: [], loading: false });
    });
});

describe('DeviceStore: einzelne Geräte', () => {
    async function loaded(): Promise<DeviceStore> {
        const store = new DeviceStore(pagedSource(devices(1, 3)).fetchPage);
        await store.refresh('open');
        return store;
    }

    it('find', async () => {
        const store = await loaded();
        expect(store.find(2)?.identifier).toBe('M2');
        expect(store.find(99)).toBeNull();
    });

    it('upsert übernimmt einen neueren Stand an derselben Stelle', async () => {
        const store = await loaded();
        store.upsert(device(2, { version: 2, status: 'defective', status_label: 'defekt' }));
        expect(store.state.devices.map((d) => [d.id, d.status])).toEqual([
            [1, 'ready'],
            [2, 'defective'],
            [3, 'ready'],
        ]);
    });

    it('upsert ignoriert ältere Versionen und unbekannte Geräte', async () => {
        const store = await loaded();
        store.upsert(device(2, { version: 3 }));
        store.upsert(device(2, { version: 2, status: 'defective', status_label: 'defekt' }));
        store.upsert(device(99));
        expect(store.find(2)?.version).toBe(3);
        expect(store.state.devices).toHaveLength(3);
    });

    it('upsert eines ausgesonderten Geräts nimmt es aus der Liste', async () => {
        const store = await loaded();
        store.upsert(device(2, { version: 2, status: 'retired', status_label: 'ausgesondert' }));
        expect(store.state.devices.map((d) => d.id)).toEqual([1, 3]);
    });

    it('clear vergisst alles', async () => {
        const store = await loaded();
        store.clear();
        expect(store.state).toMatchObject({ mode: null, devices: [], total: null });
    });
});
