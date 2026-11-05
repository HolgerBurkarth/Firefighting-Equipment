import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { apiRequest } from '../../src/api/client';
import { parseAuthState, type UserRole } from '../../src/api/auth';
import {
    afterLogin,
    canOpenRoute,
    consoleRoute,
    landingRoute,
    safeReturnPath,
} from '../../src/auth/redirect';
import {
    resetSession,
    session,
    setLoginNotice,
    setSession,
    takeLoginNotice,
} from '../../src/auth/session';
import { createAppRouter, preloadPage } from '../../src/router';
import { ADMIN_MENU, ADMIN_SETTINGS, SERVICE_MENU } from '../../src/router/adminMenu';
import { parseAppBase, type AppBase } from '../../src/runtime/appBase';
import { respond } from './support/fetch';
import { meBody, permissionsJson } from './support/me';

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

/** Konto der Kontoart `user` mit einer eigenen Rolle (Rechte aus auth/me). */
function custom(
    areas: Readonly<Record<string, 'read' | 'write'>>,
    actions: readonly string[] = [],
) {
    return parseAuthState(meBody('user', permissionsJson(areas, actions)));
}

function account(value: ReturnType<typeof parseAuthState>) {
    if (value === null) {
        throw new Error('auth/me ungültig');
    }
    return value;
}

// Die Tests prüfen Wächter, Adressen und meta, nicht die Seiten (die testen eigene Dateien). Mit den
// echten Seiten übersetzte der erste Seitenwechsel je Seite deren ganzen Baum (LoginPage samt Reka UI:
// ohne Last rund 2,3 s, alle späteren Tests wenige ms); unter Last riss der erste Test so die 5 s
// (rot am 04.11. und zweimal am 05.11.2026, parallel zu composer test).
const PAGE_STUB = defineComponent({ render: () => null });

function withoutPages(router: Router): Router {
    for (const record of router.getRoutes()) {
        const components = record.components;
        if (components) {
            for (const name of Object.keys(components)) {
                components[name] = PAGE_STUB;
            }
        }
    }
    return router;
}

async function routerAt(path: string, area = TENANT): Promise<Router> {
    const router = withoutPages(createAppRouter(area, createMemoryHistory()));
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
        setSession(state('admin'));
        expect((await routerAt('/geraete/7/details')).currentRoute.value.params['id']).toBe('7');
        expect((await routerAt('/geraete/07/details')).currentRoute.value.name).toBe('not-found');
    });

    it('Dashboard des Admins und des Super-Admins mit Menü', async () => {
        setSession(state('admin'));
        expect((await routerAt('/dashboard')).currentRoute.value.meta.layout).toBe('admin');
        setSession(state('superadmin'));
        expect((await routerAt('/dashboard', SERVICE)).currentRoute.value.meta.layout).toBe(
            'admin',
        );
    });

    it.each([
        ['/standorte', 'locations'],
        ['/geraetetypen', 'device-types'],
        ['/geraete', 'devices'],
        ['/geraete/12/details', 'device-details'],
        ['/geraete/neu', 'device-new'],
        ['/geraete/import', 'device-import'],
        ['/benutzer', 'users'],
        ['/protokoll', 'notices'],
        ['/protokoll/7', 'notice'],
    ] as const)('Seite %s der Admin-Konsole nur für Admins', async (path, name) => {
        setSession(state('admin'));
        const route = (await routerAt(path)).currentRoute.value;
        expect(route.name).toBe(name);
        expect(route.meta.layout).toBe('admin');
        setSession(state('user'));
        expect((await routerAt(path)).currentRoute.value.name).toBe('search');
        setSession(state('superadmin'));
        expect((await routerAt(path, SERVICE)).currentRoute.value.name).toBe('not-found');
    });

    it('Einstellungen unter einer Stadt für Admins, unter service für Super-Admins', async () => {
        setSession(state('admin'));
        const route = (await routerAt('/einstellungen')).currentRoute.value;
        expect(route.name).toBe('settings');
        // Konten der Stadt, geöffnet nach den Rechten (jede Konsole, Schritt 1.7b).
        expect(route.meta.roles).toEqual(['user', 'admin']);
        expect(route.meta.rights).toBeTypeOf('function');
        setSession(state('user'));
        expect((await routerAt('/einstellungen')).currentRoute.value.name).toBe('search');
        setSession(state('superadmin'));
        const service = (await routerAt('/einstellungen', SERVICE)).currentRoute.value;
        expect(service.name).toBe('settings');
        expect(service.meta.roles).toEqual(['superadmin']);
        expect(service.meta.layout).toBe('admin');
    });

    it.each([
        ['/staedte', 'tenants'],
        ['/staedte/7', 'tenant'],
        ['/super-admins', 'super-admins'],
        ['/einstellungen', 'settings'],
    ] as const)('Seite %s nur für Super-Admins unter service', async (path, name) => {
        setSession(state('superadmin'));
        const route = (await routerAt(path, SERVICE)).currentRoute.value;
        expect(route.name).toBe(name);
        expect(route.meta.layout).toBe('admin');
        expect(route.meta.roles).toEqual(['superadmin']);
    });

    it('Seiten des Super-Admins gibt es unter einer Stadt nicht', async () => {
        setSession(state('admin'));
        expect((await routerAt('/staedte')).currentRoute.value.name).toBe('not-found');
        expect((await routerAt('/staedte/7')).currentRoute.value.name).toBe('not-found');
        setSession(state('superadmin'));
        expect((await routerAt('/staedte/07', SERVICE)).currentRoute.value.name).toBe('not-found');
    });

    it('jeder Eintrag im Menü des Super-Admins hat eine Seite unter service', async () => {
        setSession(state('superadmin'));
        const router = await routerAt('/dashboard', SERVICE);
        for (const entry of [...SERVICE_MENU, ADMIN_SETTINGS]) {
            const route = router.resolve({ name: entry.route });
            expect(route.meta.layout, entry.route).toBe('admin');
            expect(route.meta.roles, entry.route).toEqual(['superadmin']);
        }
    });

    it('Mail-Link auf einen Hinweis ohne Sitzung: nach der Anmeldung zum Hinweis', async () => {
        setSession(null);
        const route = (await routerAt('/protokoll/7')).currentRoute.value;
        expect(route.name).toBe('login');
        expect(route.query['weiter']).toBe('/protokoll/7');
        expect((await routerAt('/protokoll/07')).currentRoute.value.name).toBe('not-found');
    });

    it('jeder Eintrag im Menü der Admin-Konsole hat eine Seite mit deren Darstellung', async () => {
        setSession(state('admin'));
        const router = await routerAt('/dashboard');
        for (const entry of [...ADMIN_MENU, ADMIN_SETTINGS]) {
            const route = router.resolve({ name: entry.route });
            // Suchen ist eine Handy-Seite (Leiste unten), alle anderen gehören zur Konsole.
            expect(route.meta.layout, entry.route).toBe(
                entry.route === 'search' ? 'user' : 'admin',
            );
            expect(route.meta.roles, entry.route).toContain('admin');
        }
    });

    it('Seiten ohne eigene Darstellung haben kein layout', async () => {
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
        const admin = account(state('admin'));
        const user = account(state('user'));
        expect(afterLogin(router, admin, '/dashboard')).toBe('/dashboard');
        expect(afterLogin(router, user, '/dashboard')).toEqual({ name: 'search' });
        expect(afterLogin(router, user, '/anmelden')).toEqual({ name: 'search' });
        expect(afterLogin(router, user, '/gibt-es-nicht')).toEqual({ name: 'search' });
        expect(afterLogin(router, admin, '//boese.example/')).toEqual({ name: 'dashboard' });
        expect(afterLogin(router, admin, undefined)).toEqual({ name: 'dashboard' });
        expect(landingRoute(router, admin)).toEqual({ name: 'dashboard' });
        expect(landingRoute(router, user)).toEqual({ name: 'search' });
    });

    it('nach den Rechten einer eigenen Rolle (Schritt 1.7b)', async () => {
        const router = await routerAt('/anmelden');
        const keeper = account(custom({ devices: 'write', search: 'read' }, ['device.edit']));
        expect(afterLogin(router, keeper, '/geraete/7/details')).toBe('/geraete/7/details');
        expect(afterLogin(router, keeper, '/benutzer')).toEqual({ name: 'devices' });
        expect(afterLogin(router, keeper, '/geraete/neu')).toEqual({ name: 'devices' });
    });
});

describe('Rechte der Rolle (Plan Phase 1, Schritt 1.7b)', () => {
    it.each([
        ['/geraete', { devices: 'read' }, [], 'devices'],
        ['/geraete/12/details', { devices: 'read' }, [], 'device-details'],
        ['/geraete/12', { devices: 'read' }, [], 'device'],
        ['/geraete/12', { search: 'read' }, [], 'device'],
        ['/geraete/neu', { devices: 'write' }, ['device.create'], 'device-new'],
        ['/geraete/import', { devices: 'write' }, ['csv.import'], 'device-import'],
        ['/standorte', { locations: 'read' }, [], 'locations'],
        ['/geraetetypen', { device_types: 'read' }, [], 'device-types'],
        ['/benutzer', { users: 'read' }, [], 'users'],
        ['/protokoll', { log: 'read' }, [], 'notices'],
        ['/protokoll/7', { log: 'read' }, [], 'notice'],
        ['/dashboard', { dashboard: 'read' }, [], 'dashboard'],
        ['/suchen', { search: 'read' }, [], 'search'],
        ['/prueffristen', { due: 'read' }, [], 'inspections'],
        ['/einstellungen', { log: 'read' }, [], 'settings'],
    ] as const)('%s mit %j öffnet sich', async (path, areas, actions, name) => {
        setSession(custom(areas, actions));
        expect((await routerAt(path)).currentRoute.value.name).toBe(name);
    });

    it.each([
        ['/geraete', { log: 'read' }, [], 'notices'],
        ['/geraete/neu', { devices: 'write' }, [], 'devices'],
        ['/geraete/import', { devices: 'write' }, ['device.create'], 'devices'],
        ['/benutzer', { devices: 'read' }, [], 'devices'],
        ['/dashboard', { users: 'read' }, [], 'users'],
        ['/dashboard', { search: 'read', due: 'read' }, [], 'search'],
        ['/suchen', { due: 'read' }, [], 'inspections'],
        ['/prueffristen', {}, [], 'profile'],
        ['/einstellungen', { search: 'read' }, [], 'search'],
        ['/geraete/12', { due: 'read' }, [], 'inspections'],
    ] as const)('%s mit %j: still zur Startseite %s', async (path, areas, actions, name) => {
        setSession(custom(areas, actions));
        expect((await routerAt(path)).currentRoute.value.name).toBe(name);
    });

    it('Startseite: Dashboard, sonst erste erlaubte Seite der Konsole, sonst Handy-Seiten', async () => {
        const router = await routerAt('/anmelden');
        const at = (areas: Readonly<Record<string, 'read' | 'write'>>) =>
            landingRoute(router, account(custom(areas)));
        expect(at({ dashboard: 'read', devices: 'read' })).toEqual({ name: 'dashboard' });
        expect(at({ devices: 'read', users: 'read' })).toEqual({ name: 'devices' });
        expect(at({ search: 'read', settings: 'read' })).toEqual({ name: 'settings' });
        expect(at({ search: 'read', due: 'read' })).toEqual({ name: 'search' });
        expect(at({ due: 'read' })).toEqual({ name: 'inspections' });
        expect(at({})).toEqual({ name: 'profile' });
        // „Dashboard“ der Handy-Leiste (F8): nur mit Konsole.
        expect(consoleRoute(router, account(custom({ search: 'read', due: 'read' })))).toBeNull();
        expect(consoleRoute(router, account(custom({ search: 'read', log: 'read' })))).toEqual({
            name: 'notices',
        });
    });

    it('canOpenRoute auch für Seiten mit Platzhalter (ohne Parameter)', async () => {
        const router = await routerAt('/anmelden');
        const reader = account(custom({ devices: 'read' }));
        expect(canOpenRoute(router, 'device-details', reader)).toBe(true);
        expect(canOpenRoute(router, 'notice', reader)).toBe(false);
        expect(canOpenRoute(router, 'gibt-es-nicht', reader)).toBe(false);
    });

    it('Konto ohne Rechte vom Server (App vor 1.7): Rechte der Kontoart', async () => {
        setSession(state('user'));
        expect((await routerAt('/geraete')).currentRoute.value.name).toBe('search');
        setSession(state('admin'));
        expect((await routerAt('/geraete')).currentRoute.value.name).toBe('devices');
    });
});

describe('preloadPage (Plan Phase 1, Schritt 1.7d)', () => {
    it('lädt die Programmteile der Seite sofort, ohne Wächter', () => {
        const loaded: string[] = [];
        const router = createRouter({
            history: createMemoryHistory('/firefighting/test/'),
            routes: [
                {
                    path: '/suchen',
                    component: () => {
                        loaded.push('suchen');
                        return Promise.resolve({ template: '<div />' });
                    },
                },
                {
                    path: '/profil',
                    component: () => {
                        loaded.push('profil');
                        return Promise.resolve({ template: '<div />' });
                    },
                },
            ],
        });
        // Kein Wächter läuft, keine Navigation beginnt.
        router.beforeEach(() => {
            throw new Error('Wächter');
        });
        preloadPage(router, '/suchen?q=M1#x');
        expect(loaded).toEqual(['suchen']);
    });

    it('ein Fehler beim Laden bleibt dem Router überlassen (keine unbehandelte Ablehnung)', async () => {
        const router = createRouter({
            history: createMemoryHistory('/firefighting/test/'),
            routes: [{ path: '/a', component: () => Promise.reject(new Error('chunk')) }],
        });
        expect(() => {
            preloadPage(router, '/a');
        }).not.toThrow();
        await Promise.resolve();
    });

    it('unbekannte Adresse: nichts außer der Seite „nicht gefunden“ der App', () => {
        const router = createAppRouter(TENANT, createMemoryHistory('/firefighting/test/'));
        expect(() => {
            preloadPage(router, '/gibt-es-nicht');
        }).not.toThrow();
    });
});
