import { beforeEach, describe, expect, it } from 'vitest';
import { parseClientConfig } from '../../src/api/clientConfig';
import {
    ensureUsedTarget,
    loadUsedTarget,
    rememberUsedTarget,
    usedTargetName,
    usedTargetText,
} from '../../src/data/usedTarget';

function storage(): Storage {
    const data = new Map<string, string>();
    return {
        getItem: (key: string) => data.get(key) ?? null,
        setItem: (key: string, value: string) => void data.set(key, value),
    } as unknown as Storage;
}

describe('Sammelstelle für „Benutzt melden“', () => {
    beforeEach(() => {
        usedTargetName.value = undefined;
    });

    it('Text der Rückfrage', () => {
        expect(usedTargetText('Wache')).toBe('Es kommt in die Sammelstelle Wache.');
        expect(usedTargetText(null)).toBe('Der Standort bleibt.');
        expect(usedTargetText(undefined)).toContain('falls es eine gibt');
    });

    it('wird je Stadt gemerkt und wieder geladen', () => {
        const store = storage();
        rememberUsedTarget('test', 'Wache', store);
        usedTargetName.value = undefined;
        loadUsedTarget('test', store);
        expect(usedTargetName.value).toBe('Wache');
        rememberUsedTarget('test', null, store);
        usedTargetName.value = undefined;
        loadUsedTarget('test', store);
        expect(usedTargetName.value).toBeNull();
        usedTargetName.value = undefined;
        loadUsedTarget('andere', store);
        expect(usedTargetName.value).toBeUndefined();
    });

    it('client-config liest used_target (fehlt = keine)', () => {
        const base = {
            server_time: '2026-11-09T10:00:00Z',
            offline: { max_age_days: 7, queue_max_age_days: 7 },
            max_clock_skew_seconds: 300,
        };
        expect(parseClientConfig(base)?.usedTarget).toBeNull();
        expect(
            parseClientConfig({ ...base, used_target: { id: 3, name: 'Wache' } })?.usedTarget,
        ).toBe('Wache');
    });

    describe('ensureUsedTarget', () => {
        const config = {
            serverTime: '2026-11-09T10:00:00Z',
            maxAgeDays: 7,
            queueMaxAgeDays: 7,
            maxClockSkewSeconds: 300,
            usedTarget: 'Wache',
        };

        it('lädt client-config, wenn der Wert unbekannt ist', async () => {
            const store = storage();
            await ensureUsedTarget({
                area: 'test',
                offline: false,
                fetchConfig: () => Promise.resolve({ ok: true, status: 200, data: config }),
                storage: store,
            });
            expect(usedTargetName.value).toBe('Wache');
            expect(store.getItem('ff-used-target:test')).toBe('{"name":"Wache"}');
        });

        it('fragt nicht, wenn der Wert bekannt ist oder offline gemeldet wird', async () => {
            let calls = 0;
            const fetchConfig = () => {
                calls++;
                return Promise.resolve({ ok: true, status: 200, data: config } as const);
            };
            await ensureUsedTarget({ area: 'test', offline: true, fetchConfig, storage: null });
            usedTargetName.value = null;
            await ensureUsedTarget({ area: 'test', offline: false, fetchConfig, storage: null });
            expect(calls).toBe(0);
            expect(usedTargetName.value).toBeNull();
        });

        it('wartet höchstens die Frist; ohne Antwort bleibt der Wert unbekannt', async () => {
            await ensureUsedTarget({
                area: 'test',
                offline: false,
                fetchConfig: () => new Promise(() => undefined),
                storage: null,
                timeoutMs: 10,
            });
            expect(usedTargetName.value).toBeUndefined();
        });

        it('ein Fehler lässt den Wert unbekannt', async () => {
            await ensureUsedTarget({
                area: 'test',
                offline: false,
                fetchConfig: () =>
                    Promise.resolve({
                        ok: false,
                        error: { code: 'network', message: 'x', status: null },
                    } as never),
                storage: null,
            });
            expect(usedTargetName.value).toBeUndefined();
        });
    });
});
