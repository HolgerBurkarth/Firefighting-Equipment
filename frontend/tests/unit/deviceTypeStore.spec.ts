import { describe, expect, it } from 'vitest';
import type { ApiResult } from '../../src/api/client';
import type { DeviceType } from '../../src/api/deviceTypes';
import { DeviceTypeStore } from '../../src/data/deviceTypeStore';

const TYPES: readonly DeviceType[] = [
    { id: 1, name: 'Funkgerät', active: true, deviceCount: 12 },
    { id: 2, name: 'Wärmebildkamera', active: false, deviceCount: 0 },
];

const FAILED: ApiResult<readonly DeviceType[]> = {
    ok: false,
    error: {
        code: 'network',
        message: null,
        status: null,
        retryAfterSeconds: null,
        details: null,
    },
};

describe('DeviceTypeStore', () => {
    it('lädt alle Gerätetypen und behält sie bei einem Fehler', async () => {
        const results: ApiResult<readonly DeviceType[]>[] = [
            { ok: true, status: 200, data: TYPES },
            FAILED,
        ];
        let calls = 0;
        const store = new DeviceTypeStore(() => {
            const result = results[calls] ?? FAILED;
            calls += 1;
            return Promise.resolve(result);
        });
        expect(store.state.types).toBeNull();

        await store.refresh('open');
        expect(store.state).toEqual({ types: TYPES, loading: false, error: null });

        await store.refresh('changed');
        expect(calls).toBe(2);
        expect(store.state.types).toEqual(TYPES);
        expect(store.state.error?.code).toBe('network');
    });

    it('vergisst alles beim Leeren; eine laufende Abfrage zählt danach nicht', async () => {
        let resolve: (result: ApiResult<readonly DeviceType[]>) => void = () => undefined;
        const store = new DeviceTypeStore(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const loading = store.refresh('open');
        expect(store.state.loading).toBe(true);
        store.clear();
        resolve({ ok: true, status: 200, data: TYPES });
        await loading;
        expect(store.state).toEqual({ types: null, loading: false, error: null });
    });
});
