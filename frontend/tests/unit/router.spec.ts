import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, type Router } from 'vue-router';
import { apiRequest } from '../../src/api/client';
import { parseAuthState, type UserRole } from '../../src/api/auth';
import { afterLogin, landingRoute, safeReturnPath } from '../../src/auth/redirect';
import {
    resetSession,
    session,
    setLoginNotice,
    setSession,
    takeLoginNotice,
} from '../../src/auth/session';
import { createAppRouter } from '../../src/router';
import { parseAppBase, type AppBase } from '../../src/runtime/appBase';
import { respond } from './support/fetch';
import { meBody } from './support/me';

function base(url: string): AppBase {
    const parsed = parseAppBase(url);
    if (parsed === null) {
        throw new Error(url);
    }
    return parsed;
}

const TENANT = base('https://www.prodad.de/firefighting/test/');
const SERVICE = base('https://www.prodad.de/firefighting/service/');

function state(role: UserRole) {
    return parseAuthState(meBody(role));
}

async function routerAt(path: string, area = TENANT): Promise<Router> {
    const router = createAppRouter(area, createMemoryHistory());
    await router.push(path);
    return router;
}

beforeEach(() => {
    resetSession();
    setLoginNotice(null);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('Wächter', () => {
    it('„/“ ohne Sitzung → Anmeldung', async () => {
        setSession(null);
        expect((await routerAt('/')).currentRoute.value.name).toBe('login');
    });

    it.each([
        ['user', 'search'],
        ['admin', 'dashboard'],
    ] as const)('„/“ als %s → %s', async (role, name) => {
        setSession(state(role));
        expect((await routerAt('/')).currentRoute.value.name).toBe(name);
    });

    it('Super-Admin unter service → Dashboard', async () => {
        setSession(state('superadmin'));
        expect((await routerAt('/', SERVICE)).currentRoute.value.name).toBe('dashboard');
    });

    it('geschützte Seite ohne Sitzung → Anmeldung mit Rückkehradresse', async () => {
        setSession(null);
        const route = (await routerAt('/dashboard?x=1')).currentRoute.value;
        expect(route.name).toBe('login');
        expect(route.query['weiter']).toBe('/dashboard?x=1');
    });

    it('falsche Rolle → Startseite der eigenen Rolle', async () => {
        setSession(state('user'));
        expect((await routerAt('/dashboard')).currentRoute.value.name).toBe('search');
    });

    it.each([
        ['/suchen', 'search', 'user'],
        ['/prueffristen', 'inspections', 'user'],
        ['/profil', 'profile', 'user'],
        ['/geraete/12', 'device', 'focus'],
    ] as const)('Handy-Seite %s für User und Admin', async (path, name, layout) => {
        for (const role of ['user', 'admin'] as const) {
            setSession(state(role));
            const route = (await routerAt(path)).currentRoute.value;
            expect(route.name, role).toBe(name);
            expect(route.meta.layout).toBe(layout);
        }
    });

    it('Gerät nur mit positiver Ganzzahl ohne führende Null', async () => {
        setSession(state('user'));
        expect((await routerAt('/geraete/1')).currentRoute.value.params['id']).toBe('1');
        for (const path of ['/geraete/0', '/geraete/012', '/geraete/abc', '/geraete/1x']) {
            expect((await routerAt(path)).currentRoute.value.name, path).toBe('not-found');
        }
        expect((await routerAt('/geraete/1234567890123456789')).currentRoute.value.name).toBe(
            'not-found',
        );
    });

    it('Dashboard des Admins mit Leiste bei Handy-Breite, des Super-Admins ohne', async () => {
        setSession(state('admin'));
        expect((await routerAt('/dashboard')).currentRoute.value.meta.mobileTabBar).toBe(true);
        setSession(state('superadmin'));
        expect(
            (await routerAt('/dashboard', SERVICE)).currentRoute.value.meta.mobileTabBar,
        ).toBeUndefined();
    });

    it('Seiten ohne eigene Darstellung haben kein layout', async () => {
        setSession(state('admin'));
        expect((await routerAt('/dashboard')).currentRoute.value.meta.layout).toBeUndefined();
        expect((await routerAt('/anmelden')).currentRoute.value.meta.layout).toBeUndefined();
    });

    it.each(['/suchen', '/prueffristen', '/profil', '/geraete/12'])(
        'unter service gibt es %s nicht',
        async (path) => {
            setSession(state('superadmin'));
            expect((await routerAt(path, SERVICE)).currentRoute.value.name).toBe('not-found');
        },
    );

    it('einladung-annehmen gibt es nur unter Städten, öffentlich', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        expect((await routerAt('/einladung-annehmen')).currentRoute.value.name).toBe(
            'invitation-accept',
        );
        expect(fetchSpy).not.toHaveBeenCalled();
        expect((await routerAt('/einladung-annehmen', SERVICE)).currentRoute.value.name).toBe(
            'not-found',
        );
    });

    it('registrieren und email-bestaetigen gibt es nur unter Städten, öffentlich', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        expect((await routerAt('/registrieren')).currentRoute.value.name).toBe('register');
        expect((await routerAt('/email-bestaetigen')).currentRoute.value.name).toBe('email-verify');
        expect(fetchSpy).not.toHaveBeenCalled();
        expect((await routerAt('/registrieren', SERVICE)).currentRoute.value.name).toBe(
            'not-found',
        );
        expect((await routerAt('/email-bestaetigen', SERVICE)).currentRoute.value.name).toBe(
            'not-found',
        );
    });

    it('öffentliche Seiten fragen auth/me nicht', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        await routerAt('/anmelden');
        await routerAt('/gibt-es-nicht');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('fragt auth/me für geschützte Seiten', async () => {
        const { fetch, requests } = respond(200, meBody('user'));
        vi.stubGlobal('fetch', fetch);
        expect((await routerAt('/')).currentRoute.value.name).toBe('search');
        expect(requests[0]?.url).toMatch(/\/api\/v1\/auth\/me$/);
    });
});

describe('401 einer geschützten Anfrage', () => {
    it('meldet ab und führt zur Anmeldung mit Rückkehradresse', async () => {
        setSession(state('admin'));
        const router = await routerAt('/dashboard');
        const body = { error: 'not_authenticated', message: 'x' };
        await apiRequest('devices', () => ({}), { fetchFn: respond(401, body).fetch });
        await vi.waitFor(() => {
            expect(router.currentRoute.value.name).toBe('login');
        });
        expect(router.currentRoute.value.query['weiter']).toBe('/dashboard');
        expect(session.value).toBeNull();
        expect(takeLoginNotice()).toBe('session_expired');
    });
});

describe('Rückkehr nach der Anmeldung', () => {
    it.each([
        ['/dashboard', '/dashboard'],
        ['/geraete/12?x=1#a', '/geraete/12?x=1#a'],
        ['//boese.example/', null],
        ['/\\boese.example/', null],
        ['https://boese.example/', null],
        ['javascript:alert(1)', null],
        ['dashboard', null],
        ['/a\nb', null],
        [['/dashboard'], null],
        [undefined, null],
    ])('safeReturnPath(%j) → %j', (value, expected) => {
        expect(safeReturnPath(value)).toBe(expected);
    });

    it('nur Seiten, die die Rolle öffnen darf', async () => {
        const router = await routerAt('/anmelden');
        expect(afterLogin(router, 'admin', '/dashboard')).toBe('/dashboard');
        expect(afterLogin(router, 'user', '/dashboard')).toEqual(landingRoute('user'));
        expect(afterLogin(router, 'user', '/anmelden')).toEqual(landingRoute('user'));
        expect(afterLogin(router, 'user', '/gibt-es-nicht')).toEqual(landingRoute('user'));
        expect(afterLogin(router, 'admin', '//boese.example/')).toEqual(landingRoute('admin'));
        expect(afterLogin(router, 'admin', undefined)).toEqual({ name: 'dashboard' });
    });
});
