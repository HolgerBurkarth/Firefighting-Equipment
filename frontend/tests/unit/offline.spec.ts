import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { parseAuthState } from '../../src/api/auth';
import type { ApiResult } from '../../src/api/client';
import type { DeviceListItem } from '../../src/api/devices';
import type { Location } from '../../src/api/locations';
import {
    configureStoredSession,
    ensureSession,
    logout,
    onPermissionsChanged,
    onStoredSessionRejected,
    recheckSession,
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
import { OtherQueues, otherQueueText } from '../../src/data/otherQueues';
import { MemoryDb } from '../../src/runtime/localDb';
import { respond } from './support/fetch';
import { meBody, permissionsJson } from './support/me';

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

    it('merkt sich die Rechte der Rolle mit Nummer (offline gelten sie, Schritt 1.7b)', () => {
        const json = meBody('user', permissionsJson({ devices: 'write' }, ['device.edit'], true));
        const parsed = parseAuthState(json);
        expect(parsed).not.toBeNull();
        setSession(parsed);
        const stored = parseAuthState(JSON.parse(storage.getItem(KEY) ?? 'null'));
        expect(stored?.permissions).toEqual(parsed?.permissions);
        expect(stored?.permissions?.version).toBe(parsed?.permissions?.version);
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

describe('Schnellstart mit gespeicherter Sitzung (Plan Phase 1, Schritt 1.7d)', () => {
    const RIGHTS = permissionsJson({ search: 'read', due: 'read' }, ['book.move']);
    const NEW_RIGHTS = permissionsJson({ search: 'read' }, ['book.move']);

    /** Antwortet erst, wenn der Test `answer` aufruft (auth/me hängt bis dahin). */
    function held(): { fetchFn: typeof fetch; answer: (status: number, body: unknown) => void } {
        let answer: (status: number, body: unknown) => void = () => undefined;
        const fetchFn: typeof fetch = (input, init) =>
            new Promise((resolve) => {
                answer = (status, body) => {
                    resolve(respond(status, body).fetch(input, init));
                };
            });
        return {
            fetchFn,
            answer: (status, body) => {
                answer(status, body);
            },
        };
    }

    it('mit Rechten gespeichert: sofort mit ihr, ohne Hinweis; auth/me bestätigt im Hintergrund', async () => {
        storage.setItem(KEY, JSON.stringify(meBody('user', RIGHTS)));
        const me = held();
        const first = await ensureSession({ fetchFn: me.fetchFn });
        expect(first?.user.id).toBe(7);
        expect(first?.permissions?.version).toBe(RIGHTS['version']);
        expect(session.value?.user.id).toBe(7);
        // Kein Offline-Hinweis: Der Server wurde noch gar nicht gefragt.
        expect(usingStoredSession.value).toBe(false);
        // Die Prüfung läuft noch: kein zweites auth/me (z. B. beim Zurückkehren in die App).
        let rechecks = 0;
        await recheckSession('visible', {
            fetchFn: () => {
                rechecks += 1;
                return Promise.reject(new TypeError('Failed to fetch'));
            },
            now: () => Date.now() + 60_000,
        });
        expect(rechecks).toBe(0);

        me.answer(200, meBody('user', RIGHTS));
        await flush();
        expect(session.value?.user.id).toBe(7);
        expect(usingStoredSession.value).toBe(false);
    });

    it('neue Rechte in der Antwort: wie während der Sitzung (onPermissionsChanged)', async () => {
        storage.setItem(KEY, JSON.stringify(meBody('user', RIGHTS)));
        const changed: (string | null | undefined)[] = [];
        const stop = onPermissionsChanged((state) => changed.push(state.permissions?.version));
        const me = held();
        await ensureSession({ fetchFn: me.fetchFn });
        me.answer(200, meBody('user', NEW_RIGHTS));
        await flush();
        stop();
        expect(changed).toEqual([NEW_RIGHTS['version']]);
        expect(session.value?.permissions?.version).toBe(NEW_RIGHTS['version']);
    });

    it('der Server lehnt sie ab (401): zur Anmeldung, gespeicherte Sitzung gelöscht', async () => {
        storage.setItem(KEY, JSON.stringify(meBody('user', RIGHTS)));
        let rejected = 0;
        onStoredSessionRejected(() => {
            rejected += 1;
        });
        const me = held();
        expect((await ensureSession({ fetchFn: me.fetchFn }))?.user.id).toBe(7);
        me.answer(401, { error: 'not_authenticated', message: 'x' });
        await flush();
        expect(rejected).toBe(1);
        expect(session.value).toBeNull();
        expect(storage.getItem(KEY)).toBeNull();
    });

    it('keine Verbindung: dann gilt sie als Offline-Sitzung (Hinweis), bis der Server antwortet', async () => {
        storage.setItem(KEY, JSON.stringify(meBody('user', RIGHTS)));
        let release: () => void = () => undefined;
        const waited = new Promise<void>((resolve) => {
            release = resolve;
        });
        const first = await ensureSession({
            fetchFn: sequence('abort', [200, meBody('user', RIGHTS)]),
            wait: () => waited,
        });
        expect(first?.user.id).toBe(7);
        await flush();
        expect(usingStoredSession.value).toBe(true);
        release();
        await flush();
        expect(usingStoredSession.value).toBe(false);
        expect(session.value?.user.id).toBe(7);
    });

    it('ohne gespeicherte Rechte (Sitzung aus einer App vor 1.7b): wie bisher erst nach auth/me', async () => {
        storage.setItem(KEY, JSON.stringify(meBody('user')));
        const me = held();
        let resolved = false;
        const result = ensureSession({ fetchFn: me.fetchFn }).then((state) => {
            resolved = true;
            return state;
        });
        await flush();
        expect(resolved).toBe(false);
        expect(session.value).toBeNull();
        me.answer(200, meBody('user', RIGHTS));
        expect((await result)?.permissions?.version).toBe(RIGHTS['version']);
    });

    it('Anmelden während der Wartung: die ältere Antwort ändert nichts mehr', async () => {
        storage.setItem(KEY, JSON.stringify(meBody('user', RIGHTS)));
        const me = held();
        await ensureSession({ fetchFn: me.fetchFn });
        setSession(state('admin', 9));
        me.answer(401, { error: 'not_authenticated', message: 'x' });
        await flush();
        expect(session.value?.user.id).toBe(9);
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

    it('lädt bei jeder vollständigen Synchronisierung auch die Standorte in die Kopie', async () => {
        const db = new MemoryDb();
        const deviceStore = stores([device(1)]).deviceStore;
        let locationLoads = 0;
        const locationStore = new LocationStore(() => {
            locationLoads += 1;
            return Promise.resolve({
                ok: true,
                status: 200,
                data: [
                    {
                        id: 2,
                        name: 'Lager',
                        kind: 'room',
                        kindLabel: 'Raum/Lager',
                        level: 1,
                        parentId: null,
                        active: true,
                        deviceCount: 1,
                        deviceCountTotal: 1,
                        children: [],
                    },
                ],
            } as unknown as ApiResult<readonly Location[]>);
        });
        setSession(state());
        stop = installOfflineCopy({
            area: 'test',
            devices: deviceStore,
            locations: locationStore,
            db,
        });
        await deviceStore.refresh('open');
        await flush();
        expect(locationLoads).toBe(1);
        expect(
            parseSnapshot(await db.get('snapshots', 'test:7'))?.locations?.map((l) => l.id),
        ).toEqual([2]);
        // Die nächste vollständige Synchronisierung lädt sie erneut.
        await deviceStore.refresh('changed');
        await flush();
        expect(locationLoads).toBe(2);
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

    function withRights(areas: Readonly<Record<string, 'read' | 'write'>>) {
        const parsed = parseAuthState(meBody('user', permissionsJson(areas)));
        if (parsed === null) {
            throw new Error('ungültig');
        }
        return parsed;
    }

    it('merkt sich die Rechte; mit anderen Rechten geladen: nicht gezeigt, verworfen (1.7c)', async () => {
        const db = new MemoryDb();
        const online = stores([device(1)]);
        setSession(withRights({ search: 'read' }));
        stop = installOfflineCopy({
            area: 'test',
            devices: online.deviceStore,
            locations: online.locationStore,
            db,
        });
        await online.deviceStore.refresh('open');
        await flush();
        const saved = parseSnapshot(await db.get('snapshots', 'test:7'));
        expect(saved?.format).toBe(SNAPSHOT_FORMAT);
        expect(saved?.permissions).toBe(withRights({ search: 'read' }).permissions?.version);
        stop();
        resetSession();

        // Neuer Start mit anderen Rechten (z. B. anderer Datenfilter) ohne Verbindung.
        const offline = stores('fail');
        setSession(withRights({ search: 'read', due: 'read' }));
        stop = installOfflineCopy({
            area: 'test',
            devices: offline.deviceStore,
            locations: offline.locationStore,
            db,
        });
        await flush();
        expect(offline.deviceStore.state.mode).toBeNull();
        expect(await db.get('snapshots', 'test:7')).toBeNull();
    });

    it('neue Rechte während der Sitzung verwerfen die Kopie (1.7c)', async () => {
        const db = new MemoryDb();
        const online = stores([device(1)]);
        setSession(withRights({ search: 'read' }));
        stop = installOfflineCopy({
            area: 'test',
            devices: online.deviceStore,
            locations: online.locationStore,
            db,
        });
        await online.deviceStore.refresh('open');
        await flush();
        expect(await db.get('snapshots', 'test:7')).not.toBeNull();
        setSession(withRights({ search: 'read', devices: 'read' }));
        await flush();
        expect(await db.get('snapshots', 'test:7')).toBeNull();
        expect(offlineData.syncedAt).toBeNull();
        // Das nächste vollständige Laden speichert sie mit den neuen Rechten.
        await online.deviceStore.refresh('changed');
        await flush();
        expect(parseSnapshot(await db.get('snapshots', 'test:7'))?.permissions).toBe(
            withRights({ search: 'read', devices: 'read' }).permissions?.version,
        );
    });

    it('Format 1 (vor 1.7c) wird gelesen, die Rechte gelten als unbekannt', async () => {
        const db = new MemoryDb();
        await db.put('snapshots', 'test:7', {
            format: 1,
            syncedAt: '2026-10-31T10:00:00Z',
            devices: [device(5)],
            total: 1,
            locations: null,
        });
        expect(parseSnapshot(await db.get('snapshots', 'test:7'))?.permissions).toBeNull();
        const s = stores('fail');
        setSession(withRights({ search: 'read' }));
        stop = installOfflineCopy({
            area: 'test',
            devices: s.deviceStore,
            locations: s.locationStore,
            db,
        });
        await flush();
        expect(s.deviceStore.state.devices.map((d) => d.id)).toEqual([5]);
    });

    it('Schlüssel, Prüfung und Text', () => {
        expect(snapshotKey('test', state())).toBe('test:7');
        expect(parseSnapshot({ format: 2 })).toBeNull();
        expect(
            parseSnapshot({
                format: 3,
                syncedAt: '2026-10-31T10:00:00Z',
                devices: [],
                total: 0,
                locations: null,
            }),
        ).toBeNull();
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
        // Vor dem ersten Senden und bei der vollständigen Synchronisierung.
        expect(limitReads).toBe(2);
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

    it('liest client-config bei jeder vollständigen Synchronisierung, auch ohne Buchung', async () => {
        const queue = new BookingQueue({
            db: new MemoryDb(),
            post: () => Promise.reject(new Error('nichts zu senden')),
        });
        const devices = new DeviceStore(() =>
            Promise.resolve({
                ok: true,
                status: 200,
                data: { devices: [device(1)], total: 1, limit: 200, offset: 0 },
            }),
        );
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
                        maxAgeDays: 4,
                        queueMaxAgeDays: 2,
                        maxClockSkewSeconds: 300,
                    },
                });
            },
        });
        await flush();
        expect(limitReads).toBe(0);
        await devices.refresh('open');
        await flush();
        expect(limitReads).toBe(1);
        expect(queue.currentLimits).toEqual({ maxAgeDays: 4, queueMaxAgeDays: 2 });
        expect(JSON.parse(storage.getItem('ff-offline-limits:test') ?? 'null')).toEqual({
            maxAgeDays: 4,
            queueMaxAgeDays: 2,
        });
        await devices.refresh('changed');
        await flush();
        expect(limitReads).toBe(2);
        stop();
    });
});

describe('Kontowechsel: Warteschlange des anderen Kontos bleibt (Entscheidung 55)', () => {
    it('behält sie, zeigt sie dem neuen Konto und verwirft sie erst auf Wunsch', async () => {
        const db = new MemoryDb();
        const waiting = {
            clientRef: '0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c2d',
            deviceId: 1,
            identifier: 'M1',
            deviceTypeName: 'Atemschutzmaske',
            version: 1,
            status: 'maintenance',
            statusLabel: 'in Wartung',
            occurredAt: '2026-10-31T11:00:00Z',
            state: 'waiting',
        };
        await db.put('queue', 'test:7', [waiting, { ...waiting, clientRef: 'x', state: 'done' }]);
        const queue = new BookingQueue({
            db,
            post: () =>
                Promise.resolve({
                    ok: false,
                    error: {
                        code: 'network',
                        message: null,
                        status: null,
                        retryAfterSeconds: null,
                        details: null,
                    },
                }),
        });
        const others = new OtherQueues({ area: 'test', db, storage });
        const devices = new DeviceStore(() =>
            Promise.resolve({
                ok: true,
                status: 200,
                data: { devices: [], total: 0, limit: 200, offset: 0 },
            }),
        );
        setSession(state());
        const stop = installBookingQueue({
            area: 'test',
            queue,
            devices,
            others,
            storage,
            doc: document,
            fetchLimits: () => new Promise(() => undefined),
        });
        await flush();
        expect(others.list).toEqual([]);

        // Ein anderes Konto meldet sich an: die Warteschlange von Anna bleibt gespeichert.
        setSession(state('user', 8));
        await flush();
        expect(await db.get('queue', 'test:7')).toHaveLength(2);
        expect(queue.state.key).toBe('test:8');
        expect(others.list).toEqual([{ accountId: 7, name: 'Anna', count: 1 }]);
        expect(otherQueueText({ accountId: 7, name: 'Anna', count: 1 })).toBe(
            'Auf diesem Gerät liegt 1 nicht gesendete Buchung von Anna. Sie wird gesendet, sobald Anna sich wieder anmeldet.',
        );
        expect(otherQueueText({ accountId: 7, name: 'Anna', count: 3 })).toBe(
            'Auf diesem Gerät liegen 3 nicht gesendete Buchungen von Anna. Sie werden gesendet, sobald Anna sich wieder anmeldet.',
        );

        // Anna meldet sich wieder an: ihre Buchungen sind wieder da, kein Hinweis.
        setSession(state());
        await flush();
        expect(queue.state.bookings).toHaveLength(2);
        expect(others.list).toEqual([]);

        // Verwerfen löscht nur die Warteschlange des anderen Kontos.
        setSession(state('user', 8));
        await flush();
        await others.discard(7);
        expect(await db.get('queue', 'test:7')).toBeNull();
        expect(others.list).toEqual([]);
        stop();
    });
});
