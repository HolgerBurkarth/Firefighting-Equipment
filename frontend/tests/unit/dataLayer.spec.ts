import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { ApiResult } from '../../src/api/client';
import { parseAuthState, type AuthState } from '../../src/api/auth';
import type { Location } from '../../src/api/locations';
import {
    logout,
    onSessionEnded,
    resetSession,
    setSession,
    type SessionEndReason,
} from '../../src/auth/session';
import { DeviceStore } from '../../src/data/deviceStore';
import {
    installDataLayer,
    recentDeviceIds,
    recentLocationIds,
    rememberDevice,
    rememberLocation,
} from '../../src/data';
import { setBookingNotice, takeBookingNotice } from '../../src/data/booking';
import { LocationStore } from '../../src/data/locationStore';
import { devices, pagedSource } from './support/devices';
import { respond } from './support/fetch';
import { meBody } from './support/me';

function account(id: number, slug = 'test'): AuthState {
    const body = meBody('user');
    const state = parseAuthState({
        ...body,
        user: { ...(body['user'] as object), id },
        tenant: { slug, name: 'Feuerwehr Test' },
    });
    if (state === null) {
        throw new Error('Ungültige Sitzung');
    }
    return state;
}

const TREE: readonly Location[] = [
    {
        id: 10,
        name: 'HLF 20',
        kind: 'vehicle',
        kindLabel: 'Fahrzeug',
        active: true,
        deviceCount: 0,
        deviceCountTotal: 0,
        children: [],
    },
];

function locationSource() {
    let calls = 0;
    return {
        get calls(): number {
            return calls;
        },
        fetchTree: (): Promise<ApiResult<readonly Location[]>> => {
            calls += 1;
            return Promise.resolve({ ok: true, status: 200, data: TREE });
        },
    };
}

function setVisibility(state: DocumentVisibilityState): void {
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: state });
    document.dispatchEvent(new Event('visibilitychange'));
}

describe('Sitzungsende (onSessionEnded)', () => {
    let ended = 0;
    let stop: () => void = () => undefined;

    beforeEach(() => {
        resetSession();
        ended = 0;
        stop = onSessionEnded(() => {
            ended += 1;
        });
    });

    afterEach(() => {
        stop();
    });

    it('meldet Abmelden (Sitzung null)', () => {
        setSession(account(7));
        setSession(null);
        expect(ended).toBe(1);
    });

    it('meldet resetSession (z. B. 401)', () => {
        setSession(account(7));
        resetSession();
        expect(ended).toBe(1);
    });

    it('meldet ein anderes Konto (auch derselben ID in einer anderen Stadt)', () => {
        setSession(account(7));
        setSession(account(8));
        setSession(account(8, 'andere'));
        expect(ended).toBe(2);
    });

    it('nennt Konto und Grund', async () => {
        const reasons: [number, SessionEndReason][] = [];
        const stopReasons = onSessionEnded((previous, reason) => {
            reasons.push([previous.user.id, reason]);
        });
        setSession(account(7));
        setSession(account(8));
        resetSession();
        setSession(account(9));
        expect(await logout(respond(200, { logged_out: true }).fetch)).toBeNull();
        stopReasons();
        expect(reasons).toEqual([
            [7, 'replaced'],
            [8, 'ended'],
            [9, 'logout'],
        ]);
        // Abmelden meldet das Ende genau einmal.
        expect(ended).toBe(3);
    });

    it('nicht bei derselben Sitzung oder ohne vorherige', () => {
        setSession(null);
        resetSession();
        setSession(account(7));
        setSession(account(7));
        expect(ended).toBe(0);
    });
});

describe('installDataLayer', () => {
    let uninstall: () => void = () => undefined;

    beforeEach(() => {
        resetSession();
    });

    afterEach(() => {
        uninstall();
        setVisibility('visible');
    });

    it('Abmelden löscht Geräte und Standorte', async () => {
        const deviceStore = new DeviceStore(pagedSource(devices(1, 2)).fetchPage);
        const locationStore = new LocationStore(locationSource().fetchTree);
        uninstall = installDataLayer(document, [deviceStore, locationStore]);
        setSession(account(7));
        await Promise.all([deviceStore.refresh('open'), locationStore.refresh('open')]);
        expect(deviceStore.state.devices).toHaveLength(2);
        expect(locationStore.state.locations).toEqual(TREE);

        setSession(null);
        expect(deviceStore.state.devices).toEqual([]);
        expect(deviceStore.state.mode).toBeNull();
        expect(locationStore.state.locations).toBeNull();
    });

    it('frischt beim Zurückwechseln auf, aber nur angeforderte Daten', async () => {
        let time = 1_000_000;
        const source = pagedSource(devices(1, 2));
        const locations = locationSource();
        const deviceStore = new DeviceStore(source.fetchPage, () => time);
        const locationStore = new LocationStore(locations.fetchTree, () => time);
        uninstall = installDataLayer(document, [deviceStore, locationStore]);

        await deviceStore.refresh('open');
        time += 30_000;
        setVisibility('hidden');
        expect(source.calls).toHaveLength(1);
        setVisibility('visible');
        await deviceStore.refresh('open');
        expect(source.calls).toHaveLength(2);
        // Standorte wurden nie angefordert: kein Aufruf.
        expect(locations.calls).toBe(0);
    });

    it('„Zuletzt benutzt“ löscht nur das Abmelden, nicht das Ende der Sitzung', async () => {
        const deviceStore = new DeviceStore(pagedSource([]).fetchPage);
        uninstall = installDataLayer(document, [deviceStore], window.localStorage);
        setSession(account(7));
        rememberDevice(3, window.localStorage);
        rememberDevice(5, window.localStorage);
        expect(recentDeviceIds(window.localStorage)).toEqual([5, 3]);

        resetSession();
        setSession(account(7));
        expect(recentDeviceIds(window.localStorage)).toEqual([5, 3]);

        expect(await logout(respond(200, { logged_out: true }).fetch)).toBeNull();
        setSession(account(7));
        expect(recentDeviceIds(window.localStorage)).toEqual([]);
    });

    it('Zuletzt benutzte Standorte: wie „Zuletzt benutzt“ nur beim Abmelden gelöscht', async () => {
        const deviceStore = new DeviceStore(pagedSource([]).fetchPage);
        uninstall = installDataLayer(document, [deviceStore], window.localStorage);
        setSession(account(7));
        rememberLocation(11, window.localStorage);
        rememberLocation(20, window.localStorage);
        expect(recentLocationIds(window.localStorage)).toEqual([20, 11]);

        resetSession();
        setSession(account(7));
        expect(recentLocationIds(window.localStorage)).toEqual([20, 11]);

        expect(await logout(respond(200, { logged_out: true }).fetch)).toBeNull();
        setSession(account(7));
        expect(recentLocationIds(window.localStorage)).toEqual([]);
    });

    it('Ende der Sitzung verwirft die Bestätigung einer Buchung', () => {
        uninstall = installDataLayer(document, [], window.localStorage);
        setSession(account(7));
        setBookingNotice({ kind: 'success', text: 'M1 → LF 10 · defekt' });
        resetSession();
        expect(takeBookingNotice()).toBeNull();
    });

    it('ohne Sitzung kein „Zuletzt benutzt“', () => {
        setSession(null);
        rememberDevice(3, window.localStorage);
        expect(recentDeviceIds(window.localStorage)).toEqual([]);
    });

    it('nach dem Deinstallieren keine Wirkung mehr', async () => {
        const deviceStore = new DeviceStore(pagedSource(devices(1, 2)).fetchPage);
        uninstall = installDataLayer(document, [deviceStore]);
        uninstall();
        setSession(account(7));
        await deviceStore.refresh('open');
        setSession(null);
        expect(deviceStore.state.devices).toHaveLength(2);
    });
});

describe('LocationStore', () => {
    it('lädt den Baum, Fehler behalten den bisherigen', async () => {
        let fail = false;
        const store = new LocationStore(() =>
            Promise.resolve(
                fail
                    ? {
                          ok: false,
                          error: {
                              code: 'network',
                              message: null,
                              status: null,
                              retryAfterSeconds: null,
                              details: null,
                          },
                      }
                    : { ok: true, status: 200, data: TREE },
            ),
        );
        expect(store.state.locations).toBeNull();
        await store.refresh('open');
        expect(store.state).toEqual({ locations: TREE, loading: false, error: null });
        fail = true;
        await store.refresh('changed');
        expect(store.state.locations).toEqual(TREE);
        expect(store.state.error?.code).toBe('network');
    });
});
