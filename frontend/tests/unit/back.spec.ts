import { beforeEach, describe, expect, it } from 'vitest';
import { createRouter, createWebHistory, type Router } from 'vue-router';
import { goBack, returnTo } from '../../src/router/back';

const Empty = { template: '<div />' };

function testRouter(): Router {
    return createRouter({
        // Die Memory-History kennt kein history.state.back; happy-dom hat die echte History-API.
        history: createWebHistory(),
        routes: [
            { path: '/suchen', name: 'search', component: Empty },
            { path: '/prueffristen', name: 'inspections', component: Empty },
            { path: '/geraete/:id', name: 'device', component: Empty },
        ],
    });
}

describe('goBack', () => {
    beforeEach(() => {
        // Jeder Test beginnt ohne Historie der App (sonst sähe der neue Router den alten Stand).
        window.history.replaceState(null, '', '/suchen');
    });

    it('ohne vorige Seite der App → Ersatzziel', async () => {
        const router = testRouter();
        await router.push('/geraete/12');
        await goBack(router, { name: 'search' });
        expect(router.currentRoute.value.name).toBe('search');
    });

    it('mit voriger Seite → zurück wie der Knopf des Browsers', async () => {
        const router = testRouter();
        await router.push('/prueffristen');
        await router.push('/geraete/12');
        const done = new Promise<void>((resolve) => {
            const stop = router.afterEach(() => {
                stop();
                resolve();
            });
        });
        await goBack(router, { name: 'search' });
        await done;
        expect(router.currentRoute.value.name).toBe('inspections');
    });
});

describe('returnTo', () => {
    beforeEach(() => {
        window.history.replaceState(null, '', '/suchen');
    });

    function navigated(router: Router): Promise<void> {
        return new Promise<void>((resolve) => {
            const stop = router.afterEach(() => {
                stop();
                resolve();
            });
        });
    }

    it('Ziel war die vorige Seite → zurück (nicht zweimal in der Historie)', async () => {
        const router = testRouter();
        await router.push('/suchen');
        await router.push('/geraete/12');
        const length = window.history.length;
        const done = navigated(router);
        await returnTo(router, 'search');
        await done;
        expect(router.currentRoute.value.name).toBe('search');
        expect(window.history.state).toMatchObject({ forward: '/geraete/12' });
        expect(window.history.length).toBe(length);
    });

    it('andere vorige Seite → Ziel ersetzt die aktuelle Seite', async () => {
        const router = testRouter();
        await router.push('/prueffristen');
        await router.push('/geraete/12');
        await returnTo(router, 'search');
        expect(router.currentRoute.value.name).toBe('search');
        expect(window.history.state).toMatchObject({ back: '/prueffristen' });
    });

    it('ohne vorige Seite → Ziel ersetzt die aktuelle Seite', async () => {
        const router = testRouter();
        await router.push('/geraete/12');
        await returnTo(router, 'search');
        expect(router.currentRoute.value.name).toBe('search');
    });
});
