import { beforeEach, describe, expect, it } from 'vitest';
import { parseClientConfig } from '../../src/api/clientConfig';
import {
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
});
