import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { parseAuthState } from '../../src/api/auth';
import type { DeviceListItem } from '../../src/api/devices';
import {
    configureStoredSession,
    ensureSession,
    logout,
    onStoredSessionRejected,
    resetSession,
    session,
    setSession,
    usingStoredSession,
} from '../../src/auth/session';
import { BookingQueue } from '../../src/data/bookingQueue';
import { DeviceStore } from '../../src/data/deviceStore';
import { LocationStore } from '../../src/data/locationStore';
import {
    installBookingQueue,
    installOfflineCopy,
    offlineData,
    parseSnapshot,
    SNAPSHOT_FORMAT,
    snapshotKey,
    syncedAtText,
} from '../../src/data/offline';
import {
    installConnectivity,
    isOffline,
    onBackOnline,
    reportServerReached,
    resetConnectivity,
} from '../../src/runtime/connectivity';
import { MemoryDb } from '../../src/runtime/localDb';
import { respond } from './support/fetch';
import { meBody } from './support/me';

class MemoryStorage {
    readonly items = new Map<string, string>();
    getItem(key: string): string | null {
        return this.items.get(key) ?? null;
    }
    setItem(key: string, value: string): void {
        this.items.set(key, value);
    }
    removeItem(key: string): void {
        this.items.delete(key);
    }
}

const KEY = 'ff-session:test';

function sequence(...answers: ([number, unknown] | 'abort')[]): typeof fetch {
    let i = 0;
    return (input, init) => {
        const answer = answers[Math.min(i, answers.length - 1)];
        i += 1;
        if (answer === undefined || answer === 'abort') {
            return Promise.reject(new TypeError('Failed to fetch'));
        }
        return respond(answer[0], answer[1]).fetch(input, init);
    };
}

function state(role: 'user' | 'admin' = 'user', id = 7) {
    const json = meBody(role);
    (json['user'] as Record<string, unknown>)['id'] = id;
    const parsed = parseAuthState(json);
    if (parsed === null) {
        throw new Error('ungültig');
    }
    return parsed;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 20; i += 1) {
        await Promise.resolve();
    }
}

let storage: MemoryStorage;

beforeEach(() => {
    storage = new MemoryStorage();
    configureStoredSession(KEY, storage);
    resetSession();
    resetConnectivity();
});

afterEach(() => {
    onStoredSessionRejected(null);
    configureStoredSession(null);
    resetSession();
});

describe('gespeicherte Sitzung (Offline-Modus, Entscheidung 50)', () => {
    it('merkt sich jede bestätigte Sitzung ohne Cookie, vergisst sie beim Zurücksetzen', () => {
        setSession(state('admin'));
        const stored = JSON.parse(storage.getItem(KEY) ?? 'null') as Record<string, unknown>;
        expect(parseAuthState(stored)?.user.role).toBe('admin');
        expect(JSON.stringify(stored)).not.toContain('session');
        resetSession();
        expect(storage.getItem(KEY)).toBeNull();
    });

    it('bei einer Störung sofort mit der gespeicherten Sitzung, im Hintergrund weiter fragen', async () => {
        setSession(state());
        resetSession();
        setSession(state());
        // Neuer Seitenaufruf: Sitzung unbekannt, gespeichert ist sie noch.
        const saved = storage.getItem(KEY);
        resetSession();
        storage.setItem(KEY, saved ?? '');
        let release: () => void = () => undefined;
        const waited = new Promise<void>((resolve) => {
            release = resolve;
        });
        const first = await ensureSession({
            fetchFn: sequence('abort', [200, meBody('user')]),
            wait: () => waited,
        });
        expect(first?.user.id).toBe(7);
        expect(usingStoredSession.value).toBe(true);
        expect(session.value?.user.id).toBe(7);

        release();
        await flush();
        expect(usingStoredSession.value).toBe(false);
        expect(session.value?.user.id).toBe(7);
    });

    it('lehnt der Server sie danach ab (401), meldet die App ab', async () => {
        storage.setItem(KEY, JSON.stringify(meBody('user')));
        let rejected = 0;
        onStoredSessionRejected(() => {
            rejected += 1;
        });
        const first = await ensureSession({
            fetchFn: sequence('abort', [401, { error: 'not_authenticated', message: 'x' }]),
            wait: () => Promise.resolve(),
        });
        expect(first?.user.id).toBe(7);
        await flush();
        expect(rejected).toBe(1);
        expect(session.value).toBeNull();
        expect(storage.getItem(KEY)).toBeNull();
    });

    it('ohne gespeicherte Sitzung wie bisher: warten, bis der Server antwortet', async () => {
        const waits: number[] = [];
        const result = await ensureSession({
            fetchFn: sequence('abort', 'abort', [200, meBody('user')]),
            wait: (ms) => {
                waits.push(ms);
                return Promise.resolve();
            },
        });
        expect(waits).toEqual([2000, 4000]);
        expect(result?.user.id).toBe(7);
        expect(usingStoredSession.value).toBe(false);
    });

    it('Abmelden löscht sie', async () => {
        setSession(state());
        expect(await logout(respond(200, { logged_out: true }).fetch)).toBeNull();
        expect(storage.getItem(KEY)).toBeNull();
    });

    it('unlesbarer Inhalt zählt nicht', async () => {
        storage.setItem(KEY, '{kaputt');
        const result = await ensureSession({
            fetchFn: sequence('abort', [401, { error: 'not_authenticated', message: 'x' }]),
            wait: () => Promise.resolve(),
        });
        expect(result).toBeNull();
    });
});

describe('Erreichbarkeit (Entscheidung 51)', () => {
    it('offline bei Netzwerkfehler bis zur nächsten Antwort und bei offline des Browsers', () => {
        expect(isOffline.value).toBe(false);
        reportServerReached(false);
        expect(isOffline.value).toBe(true);
        reportServerReached(true);
        expect(isOffline.value).toBe(false);

        const target = new EventTarget() as EventTarget & { navigator: { onLine: boolean } };
        target.navigator = { onLine: false };
        let back = 0;
        onBackOnline(() => {
            back += 1;
        });
        const stop = installConnectivity(target as unknown as Window);
        expect(isOffline.value).toBe(true);
        target.dispatchEvent(new Event('online'));
        expect(isOffline.value).toBe(false);
        expect(back).toBe(1);
        target.dispatchEvent(new Event('offline'));
        expect(isOffline.value).toBe(true);
        stop();
    });
});

function device(id: number): DeviceListItem {
    return {
        id,
        identifier: `M${String(id)}`,
        scanCode: null,
        deviceType: { id: 1, name: 'Atemschutzmaske' },
        location: { id: 2, name: 'Lager', path: 'Lager' },
        status: 'ready',
        statusLabel: 'einsatzbereit',
        nextInspectionDue: null,
        version: 1,
        serialNumber: null,
        manufacturer: null,
        model: null,
        stateChangedAt: '2026-10-31T10:00:00Z',
    } as unknown as DeviceListItem;
}

describe('lokale Kopie (Entscheidung 49)', () => {
    let stop: (() => void) | null = null;

    afterEach(() => {
        stop?.();
        stop = null;
    });

    function stores(devices: DeviceListItem[] | 'fail') {
        const deviceStore = new DeviceStore(() =>
            Promise.resolve(
                devices === 'fail'
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
                    : {
                          ok: true,
                          status: 200,
                          data: { devices, total: devices.length, limit: 200, offset: 0 },
                      },
            ),
        );
        const locationStore = new LocationStore(() =>
            Promise.resolve({ ok: true, status: 200, data: [] }),
        );
        return { deviceStore, locationStore };
    }

    it('speichert nach dem Laden, zeigt die Kopie beim nächsten Start ohne Verbindung', async () => {
        const db = new MemoryDb();
        const online = stores([device(1), device(2)]);
        setSession(state());
        stop = installOfflineCopy({
            area: 'test',
            devices: online.deviceStore,
            locations: online.locationStore,
            db,
            now: () => new Date('2026-10-31T13:02:00Z'),
        });
        // Standorte vor den Geräten: noch nichts gespeichert (die Geräte kamen nicht vom Server).
        await online.locationStore.refresh('open');
        await flush();
        expect(await db.get('snapshots', 'test:7')).toBeNull();
        await online.deviceStore.refresh('open');
        await flush();
        const saved = parseSnapshot(await db.get('snapshots', 'test:7'));
        expect(saved?.devices.map((d) => d.id)).toEqual([1, 2]);
        expect(saved?.syncedAt).toBe('2026-10-31T13:02:00.000Z');
        expect(saved?.locations).toEqual([]);
        stop();

        // Neuer Start ohne Verbindung.
        const offline = stores('fail');
        stop = installOfflineCopy({
            area: 'test',
            devices: offline.deviceStore,
            locations: offline.locationStore,
            db,
        });
        await flush();
        expect(offline.deviceStore.state.devices.map((d) => d.id)).toEqual([1, 2]);
        expect(offline.deviceStore.state.mode).toBe('local');
        expect(offlineData.syncedAt).toBe('2026-10-31T13:02:00.000Z');
        // Das Laden scheitert, die Kopie bleibt; gespeichert wird nichts Neues.
        await offline.deviceStore.refresh('open');
        expect(offline.deviceStore.state.devices).toHaveLength(2);
        expect(parseSnapshot(await db.get('snapshots', 'test:7'))?.syncedAt).toBe(
            '2026-10-31T13:02:00.000Z',
        );
    });

    it('je Konto getrennt; Abmelden und ein anderes Konto löschen die Kopie, 401 nicht', async () => {
        const db = new MemoryDb();
        await db.put('snapshots', 'test:7', {
            format: SNAPSHOT_FORMAT,
            syncedAt: '2026-10-31T10:00:00Z',
            devices: [device(5)],
            total: 1,
            locations: null,
        });
        const s = stores('fail');
        stop = installOfflineCopy({
            area: 'test',
            devices: s.deviceStore,
            locations: s.locationStore,
            db,
        });
        setSession(state('user', 8));
        await flush();
        expect(s.deviceStore.state.mode).toBeNull();

        // Abgelaufen (wie nach 401): Kopie von Konto 8 gäbe es nicht, Konto 7 bleibt.
        resetSession();
        setSession(state('user', 7));
        await flush();
        expect(s.deviceStore.state.devices.map((d) => d.id)).toEqual([5]);
        resetSession();
        await flush();
        expect(await db.get('snapshots', 'test:7')).not.toBeNull();

        // Ein anderes Konto ersetzt die Sitzung: die Kopie des bisherigen ist weg.
        setSession(state('user', 7));
        setSession(state('user', 9));
        await flush();
        expect(await db.get('snapshots', 'test:7')).toBeNull();
    });

    it('Schlüssel, Prüfung und Text', () => {
        expect(snapshotKey('test', state())).toBe('test:7');
        expect(parseSnapshot({ format: 2 })).toBeNull();
        expect(parseSnapshot(null)).toBeNull();
        expect(syncedAtText('2026-10-31T13:02:00Z')).toMatch(/^31\.10\., \d\d:02$/);
    });
});

describe('Warteschlange an Konto und Geräte gebunden (Entscheidung 52)', () => {
    it('zeigt wartende Buchungen in der Liste, liest die Grenzen, sendet und lädt neu', async () => {
        const db = new MemoryDb();
        await db.put('queue', 'test:7', [
            {
                clientRef: '0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c2d',
                deviceId: 1,
                identifier: 'M1',
                deviceTypeName: 'Atemschutzmaske',
                version: 1,
                status: 'maintenance',
                statusLabel: 'in Wartung',
                occurredAt: '2026-10-31T11:00:00Z',
                state: 'waiting',
            },
        ]);
        const posted: unknown[] = [];
        let releasePost: () => void = () => undefined;
        const postDone = new Promise<void>((resolve) => {
            releasePost = resolve;
        });
        const queue = new BookingQueue({
            db,
            now: () => new Date('2026-10-31T12:00:00Z'),
            post: async (_id, request) => {
                posted.push(request);
                await postDone;
                return {
                    ok: false,
                    error: {
                        code: 'network',
                        message: null,
                        status: null,
                        retryAfterSeconds: null,
                        details: null,
                    },
                };
            },
        });
        let loads = 0;
        const devices = new DeviceStore(() => {
            loads += 1;
            return Promise.resolve({
                ok: true,
                status: 200,
                data: { devices: [device(1)], total: 1, limit: 200, offset: 0 },
            });
        });
        const storage = new MemoryStorage();
        let limitReads = 0;
        setSession(state());
        const stop = installBookingQueue({
            area: 'test',
            queue,
            devices,
            storage,
            doc: document,
            fetchLimits: () => {
                limitReads += 1;
                return Promise.resolve({
                    ok: true,
                    status: 200,
                    data: {
                        serverTime: '2026-10-31T12:00:00Z',
                        maxAgeDays: 5,
                        queueMaxAgeDays: 3,
                        maxClockSkewSeconds: 300,
                    },
                });
            },
        });
        await flush();
        expect(queue.state.bookings).toHaveLength(1);
        await devices.refresh('open');
        await flush();
        // Gewünschter Stand in der Liste, solange der Server das Gerät nicht geändert hat.
        expect(devices.find(1)?.status).toBe('maintenance');
        expect(posted).toHaveLength(1);
        expect(limitReads).toBe(1);
        expect(queue.currentLimits).toEqual({ maxAgeDays: 5, queueMaxAgeDays: 3 });
        expect(JSON.parse(storage.getItem('ff-offline-limits:test') ?? 'null')).toEqual({
            maxAgeDays: 5,
            queueMaxAgeDays: 3,
        });
        releasePost();
        await flush();
        expect(queue.state.bookings[0]?.state).toBe('waiting');
        expect(loads).toBe(1);

        // Abmelden löscht die Warteschlange des Kontos.
        expect(await logout(respond(200, { logged_out: true }).fetch)).toBeNull();
        await flush();
        expect(await db.get('queue', 'test:7')).toBeNull();
        stop();
    });
});
