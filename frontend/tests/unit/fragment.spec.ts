import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { readFragmentParam, takeFragmentParam } from '../../src/runtime/fragment';

describe('readFragmentParam', () => {
    it.each([
        ['#token=abc-DEF_123', 'token', 'abc-DEF_123'],
        ['token=abc', 'token', 'abc'],
        ['#code=ABCD-EFGH-JKLM', 'code', 'ABCD-EFGH-JKLM'],
        ['#a=1&token=xyz', 'token', 'xyz'],
        ['#token=', 'token', null],
        ['', 'token', null],
        ['#code=X', 'token', null],
    ])('%j (%s) → %j', (hash, name, expected) => {
        expect(readFragmentParam(hash, name)).toBe(expected);
    });
});

describe('takeFragmentParam', () => {
    it('liest den Wert und entfernt das Fragment aus der Adresse', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/:p(.*)*', component: { template: '<div />' } }],
        });
        await router.push('/passwort-zuruecksetzen?x=1#token=geheim');
        const value = takeFragmentParam(router, router.currentRoute.value, 'token');
        expect(value).toBe('geheim');
        await router.isReady();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(router.currentRoute.value.fullPath).toBe('/passwort-zuruecksetzen?x=1');
    });
});
