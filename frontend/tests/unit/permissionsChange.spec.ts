import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import { createMemoryHistory, type Router } from 'vue-router';
import { parseAuthState } from '../../src/api/auth';
import { apiDownload, apiRequest, setForbiddenHandler } from '../../src/api/client';
import {
    onPermissionsChanged,
    onStoredSessionRejected,
    RECHECK_MIN_INTERVAL_MS,
    recheckSession,
    resetSession,
    session,
    setSession,
} from '../../src/auth/session';
import { Refresher } from '../../src/data/refresher';
import { createAppRouter } from '../../src/router';
import { parseAppBase } from '../../src/runtime/appBase';
import { registerUnsavedInput } from '../../src/runtime/unsavedInput';
import { respond } from './support/fetch';
import { meBody, permissionsJson } from './support/me';

/**
 * Rechte ändern sich während der Sitzung (Plan Phase 1, Schritt 1.7c): auth/me erneut beim
 * Zurückkehren in die App und nach 403, neue `permissions.version` meldet onPermissionsChanged,
 * der Router verlässt eine nicht mehr erlaubte Seite.
 */

type Areas = Readonly<Record<string, 'read' | 'write'>>;

function rights(areas: Areas, actions: readonly string[] = []) {
    return meBody('user', permissionsJson(areas, actions));
}

function account(areas: Areas, actions: readonly string[] = []) {
    const state = parseAuthState(rights(areas, actions));
    if (state === null) {
        throw new Error('auth/me ungültig');
    }
    return state;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 20; i += 1) {
        await Promise.resolve();
    }
}

beforeEach(() => {
    resetSession();
});

afterEach(() => {
    onStoredSessionRejected(null);
    setForbiddenHandler(null);
    resetSession();
});

describe('onPermissionsChanged', () => {
    it('meldet neue Rechte desselben Kontos mit dem neuen Stand, sonst nicht', () => {
        const seen: (string | null)[] = [];
        const now: (string | null)[] = [];
        const stop = onPermissionsChanged((state) => {
            // Der neue Stand ist schon gesetzt, wenn die Empfänger laufen.
            seen.push(state.permissions?.version ?? null);
            now.push(session.value?.permissions?.version ?? null);
        });
        setSession(account({ search: 'read' }));
        setSession(account({ search: 'read' }));
        expect(seen).toEqual([]);
        setSession(account({ search: 'read', due: 'read' }));
        expect(seen).toEqual([account({ search: 'read', due: 'read' }).permissions?.version]);
        expect(now).toEqual(seen);
        // Ein anderes Konto ist kein Rechtewechsel (dafür onSessionEnded).
        const other = rights({ devices: 'read' });
        (other['user'] as Record<string, unknown>)['id'] = 8;
        setSession(parseAuthState(other));
        expect(seen).toHaveLength(1);
        stop();
    });
});

describe('recheckSession', () => {
    it('fragt auth/me erst nach dem Mindestabstand, je Anlass', async () => {
        setSession(account({ search: 'read' }));
        const confirmed = Date.now();
        const { fetch, requests } = respond(200, rights({ search: 'read' }));
        await recheckSession('visible', { fetchFn: fetch, now: () => confirmed + 1000 });
        await recheckSession('forbidden', { fetchFn: fetch, now: () => confirmed + 1000 });
        expect(requests).toHaveLength(0);
        await recheckSession('forbidden', {
            fetchFn: fetch,
            now: () => confirmed + RECHECK_MIN_INTERVAL_MS.forbidden + 1,
        });
        expect(requests).toHaveLength(1);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/auth\/me$/);
    });

    it('neue Rechte übernimmt setSession (onPermissionsChanged); höchstens eine Abfrage', async () => {
        setSession(account({ search: 'read' }));
        const changed = vi.fn();
        const stop = onPermissionsChanged(changed);
        const { fetch, requests } = respond(200, rights({ search: 'read', devices: 'read' }));
        const later = () => Date.now() + RECHECK_MIN_INTERVAL_MS.visible + 1;
        await Promise.all([
            recheckSession('visible', { fetchFn: fetch, now: later }),
            recheckSession('forbidden', { fetchFn: fetch, now: later }),
        ]);
        expect(requests).toHaveLength(1);
        expect(changed).toHaveBeenCalledTimes(1);
        expect(session.value?.permissions?.areas).toEqual({ search: 'read', devices: 'read' });
        stop();
    });

    it('echte Ablehnung (401) führt zur Anmeldung, eine Störung ändert nichts', async () => {
        const rejected = vi.fn();
        onStoredSessionRejected(rejected);
        setSession(account({ search: 'read' }));
        const later = () => Date.now() + RECHECK_MIN_INTERVAL_MS.visible + 1;
        await recheckSession('visible', {
            fetchFn: respond(503, { error: 'database_unavailable', message: 'x' }).fetch,
            now: later,
        });
        expect(rejected).not.toHaveBeenCalled();
        expect(session.value).not.toBeNull();
        await recheckSession('visible', {
            fetchFn: respond(401, { error: 'not_authenticated', message: 'x' }).fetch,
            now: later,
        });
        expect(rejected).toHaveBeenCalledTimes(1);
    });

    it('ohne bekannte Sitzung keine Abfrage', async () => {
        const { fetch, requests } = respond(200, rights({ search: 'read' }));
        await recheckSession('forbidden', { fetchFn: fetch, now: () => Date.now() + 60_000 });
        expect(requests).toHaveLength(0);
    });
});

describe('403 forbidden meldet sich (setForbiddenHandler)', () => {
    it('nur bei forbidden einer nicht öffentlichen Anfrage bzw. eines Downloads', async () => {
        const forbidden = vi.fn();
        setForbiddenHandler(forbidden);
        const body = { error: 'forbidden', message: 'x' };
        await apiRequest('users', () => ({}), { fetchFn: respond(403, body).fetch });
        expect(forbidden).toHaveBeenCalledTimes(1);
        await apiDownload('devices/export', 'x.csv', respond(403, body).fetch);
        expect(forbidden).toHaveBeenCalledTimes(2);
        await apiRequest('auth/login', () => ({}), {
            method: 'POST',
            public: true,
            fetchFn: respond(403, body).fetch,
        });
        await apiRequest('auth/password', () => ({}), {
            method: 'POST',
            fetchFn: respond(403, { error: 'invalid_current_password', message: 'x' }).fetch,
        });
        expect(forbidden).toHaveBeenCalledTimes(2);
    });
});

describe('Router bei neuen Rechten', () => {
    const PAGE_STUB = defineComponent({ render: () => null });
    const base = parseAppBase('https://www.prodad.de/firefighting/test/');

    async function routerAt(path: string): Promise<Router> {
        if (base === null) {
            throw new Error('base');
        }
        const router = createAppRouter(base, createMemoryHistory());
        for (const record of router.getRoutes()) {
            const components = record.components;
            if (components) {
                for (const name of Object.keys(components)) {
                    components[name] = PAGE_STUB;
                }
            }
        }
        await router.push(path);
        return router;
    }

    it('verlässt eine nicht mehr erlaubte Seite still zur Startseite', async () => {
        setSession(account({ devices: 'read', search: 'read' }));
        const router = await routerAt('/geraete');
        expect(router.currentRoute.value.name).toBe('devices');
        setSession(account({ search: 'read' }));
        await vi.waitFor(() => {
            expect(router.currentRoute.value.name).toBe('search');
        });
    });

    it('bleibt mit ungespeicherten Eingaben und auf weiter erlaubten Seiten', async () => {
        setSession(account({ devices: 'read', search: 'read' }));
        const router = await routerAt('/geraete');
        const stopDirty = registerUnsavedInput(() => true);
        setSession(account({ search: 'read' }));
        await flush();
        expect(router.currentRoute.value.name).toBe('devices');
        stopDirty();

        setSession(account({ devices: 'read', search: 'read', due: 'read' }));
        await flush();
        expect(router.currentRoute.value.name).toBe('devices');
    });
});

describe('Refresher, Anlass rights', () => {
    it('lädt nur, was schon angefordert wurde, dann ohne Mindestabstand', async () => {
        let loads = 0;
        const refresher = new Refresher(() => {
            loads += 1;
            return Promise.resolve(true);
        });
        await refresher.refresh('rights');
        expect(loads).toBe(0);
        await refresher.refresh('open');
        await refresher.refresh('rights');
        expect(loads).toBe(2);
    });
});
