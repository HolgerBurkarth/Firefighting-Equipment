import { afterEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import {
    TAKEOVER_TIMEOUT_MS,
    AppUpdater,
    fetchServedBuildId,
    installUpdateGuard,
    isChunkLoadError,
    RELOAD_COOLDOWN_MS,
    RELOAD_STORAGE_KEY,
    REQUEST_SETTLE_TIMEOUT_MS,
    SKIP_WAITING_MESSAGE,
    START_CHECK_TIMEOUT_MS,
    START_RETRY_DELAYS_MS,
    UPDATE_RETRY_DELAYS_MS,
    updateNotice,
    type UpdateEnvironment,
    type UpdateWorker,
} from '../../src/runtime/appUpdate';
import { registerUnsavedInput } from '../../src/runtime/unsavedInput';

type Listener = (event: Event) => void;

class FakeTarget {
    private readonly listeners = new Map<string, Listener[]>();

    addEventListener(type: string, listener: Listener): void {
        this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]);
    }

    removeEventListener(type: string, listener: Listener): void {
        this.listeners.set(
            type,
            (this.listeners.get(type) ?? []).filter((entry) => entry !== listener),
        );
    }

    emit(type: string): void {
        for (const listener of this.listeners.get(type) ?? []) {
            listener(new Event(type));
        }
    }
}

class FakeWorker extends FakeTarget implements UpdateWorker {
    readonly messages: unknown[] = [];
    onMessage: (() => void) | null = null;

    constructor(public state: string) {
        super();
    }

    postMessage(message: unknown): void {
        this.messages.push(message);
        this.onMessage?.();
    }

    setState(state: string): void {
        this.state = state;
        this.emit('statechange');
    }
}

class FakeRegistration extends FakeTarget {
    waiting: FakeWorker | null = null;
    installing: FakeWorker | null = null;
    updates = 0;
    failUpdate = false;
    unregistered = false;

    unregister(): Promise<boolean> {
        this.unregistered = true;
        return Promise.resolve(true);
    }

    update(): Promise<unknown> {
        this.updates += 1;
        return this.failUpdate ? Promise.reject(new TypeError('offline')) : Promise.resolve();
    }
}

class FakeContainer extends FakeTarget {
    constructor(public controller: unknown) {
        super();
    }
}

class MemoryStorage {
    readonly items = new Map<string, string>();

    getItem(key: string): string | null {
        return this.items.get(key) ?? null;
    }

    setItem(key: string, value: string): void {
        this.items.set(key, value);
    }
}

interface Setup {
    readonly updater: AppUpdater;
    readonly registration: FakeRegistration;
    readonly container: FakeContainer;
    readonly storage: MemoryStorage;
    readonly navigated: string[];
    readonly timers: { callback: () => void; ms: number }[];
    /** So viele Zeitgeber hat runTimers schon ausgelöst. */
    firedTimers: number;
    online: boolean;
    now: number;
    /** Kennung, die der Server liefert (build.json); null = nicht lesbar. */
    served: string | null;
    buildFetches: number;
}

const RUNNING_BUILD = 'alt-123';
const NEW_BUILD = 'neu-456';

/** Umgebung ohne Registrierung, Speicher und Zeitgeber (für einzelne Fälle). */
function bareEnv(
    container: FakeContainer,
    served: string | null = RUNNING_BUILD,
): UpdateEnvironment {
    return {
        container,
        storage: null,
        now: () => 0,
        isOnline: () => true,
        navigate: () => undefined,
        setTimeout: () => undefined,
        buildId: RUNNING_BUILD,
        fetchBuildId: () => Promise.resolve(served),
    };
}

/** Offene Promises (Prüfung der Build-Kennung) abarbeiten. */
async function flush(): Promise<void> {
    for (let i = 0; i < 20; i += 1) {
        await Promise.resolve();
    }
}

function setup(options: { controller?: unknown; storage?: MemoryStorage | null } = {}): Setup {
    const container = new FakeContainer(
        'controller' in options ? options.controller : { scriptURL: 'sw.js' },
    );
    const storage = new MemoryStorage();
    const state: Omit<Setup, 'updater'> = {
        registration: new FakeRegistration(),
        container,
        storage,
        navigated: [],
        timers: [],
        firedTimers: 0,
        online: true,
        now: 1_000_000,
        served: RUNNING_BUILD,
        buildFetches: 0,
    };
    const env: UpdateEnvironment = {
        container,
        storage: options.storage === null ? null : (options.storage ?? storage),
        now: () => state.now,
        isOnline: () => state.online,
        navigate: (url) => state.navigated.push(url),
        setTimeout: (callback, ms) => state.timers.push({ callback, ms }),
        buildId: RUNNING_BUILD,
        fetchBuildId: () => {
            state.buildFetches += 1;
            return Promise.resolve(state.served);
        },
    };
    const updater = new AppUpdater(env);
    updater.attach(state.registration);
    return Object.assign(state, { updater });
}

/**
 * Zeitgeber der Reihe nach auslösen (auch neu hinzukommende), bis keiner mehr kommt. Liefert die
 * Wartezeiten der ausgelösten Zeitgeber.
 */
async function runTimers(s: Setup): Promise<number[]> {
    const fired: number[] = [];
    await flush();
    while (s.firedTimers < s.timers.length) {
        const timer = s.timers[s.firedTimers];
        s.firedTimers += 1;
        timer?.callback();
        if (timer !== undefined) {
            fired.push(timer.ms);
        }
        await flush();
    }
    return fired;
}

/**
 * Der Browser findet die neue Version erst beim `n`-ten update() (die früheren zusammengelegt); sie
 * wartet dann installiert.
 */
function findsNewVersionOnUpdate(s: Setup, n: number): FakeWorker {
    const worker = new FakeWorker('installed');
    s.registration.update = () => {
        s.registration.updates += 1;
        if (s.registration.updates === n) {
            s.registration.waiting = worker;
            s.registration.emit('updatefound');
        }
        return Promise.resolve();
    };
    return worker;
}

/** Neue Version installieren: updatefound → installing → installed (wartet). */
function installNewVersion(s: Setup): FakeWorker {
    const worker = new FakeWorker('installing');
    s.registration.installing = worker;
    s.registration.emit('updatefound');
    s.registration.installing = null;
    s.registration.waiting = worker;
    worker.setState('installed');
    return worker;
}

/** Der wartende Worker übernimmt auf SKIP_WAITING (wie Workbox ohne skipWaiting). */
function activatesOnMessage(s: Setup, worker: FakeWorker): void {
    worker.onMessage = () => {
        s.registration.waiting = null;
        s.container.emit('controllerchange');
    };
}

const unregister: (() => void)[] = [];

afterEach(() => {
    for (const fn of unregister.splice(0)) {
        fn();
    }
});

describe('AppUpdater: neue Version erkennen', () => {
    it('erste Installation (noch kein Service Worker in Kontrolle) ist kein Update', () => {
        const s = setup({ controller: null });
        installNewVersion(s);
        expect(s.updater.ready.value).toBe(false);
    });

    it('erste Installation, WebKit meldet den neuen Service Worker schon als controller → kein Update', async () => {
        // Reihenfolge in WebKit: controller ist schon der neue Worker, sein Zustand noch „installed“.
        const s = setup({ controller: null });
        const worker = new FakeWorker('installing');
        s.registration.installing = worker;
        s.registration.emit('updatefound');
        s.registration.installing = null;
        s.container.controller = worker;
        worker.setState('installed');
        await flush();
        expect(s.updater.ready.value).toBe(false);
        // Entschieden hat die Build-Kennung (gleich): beim Seitenwechsel kein Aktualisieren.
        expect(s.buildFetches).toBe(1);
        expect(await s.updater.applyNow('https://x/a')).toBe(false);
        expect(s.registration.updates).toBe(0);
    });

    it('erste Installation, WebKit-Reihenfolge, Server liefert schon eine andere Version → bereit', async () => {
        const s = setup({ controller: null });
        s.served = NEW_BUILD;
        const worker = new FakeWorker('installing');
        s.registration.installing = worker;
        s.registration.emit('updatefound');
        s.registration.installing = null;
        s.container.controller = worker;
        worker.setState('installed');
        await flush();
        expect(s.updater.ready.value).toBe(true);
    });

    it('erste Übernahme nach der Installation (clientsClaim) ist kein Update', async () => {
        const s = setup({ controller: null });
        s.container.controller = { scriptURL: 'sw.js' };
        s.container.emit('controllerchange');
        await flush();
        expect(s.buildFetches).toBe(1);
        expect(s.updater.ready.value).toBe(false);
    });

    it('nach der ersten Übernahme ein Deploy: erst den neuen Service Worker holen, dann neu laden', async () => {
        // Die übernehmende Version ist die eigene; ihr Service Worker lieferte die alte Seite.
        const s = setup({ controller: null });
        s.container.controller = { scriptURL: 'sw.js' };
        s.container.emit('controllerchange');
        await flush();
        s.served = NEW_BUILD;
        const worker = findsNewVersionOnUpdate(s, 2);
        activatesOnMessage(s, worker);
        await s.updater.check();
        const done = s.updater.applyNow('https://x/a');
        await runTimers(s);
        expect(await done).toBe(true);
        expect(worker.messages).toEqual([SKIP_WAITING_MESSAGE]);
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('Seite ohne Kontrolle (Strg+F5) von neuer Version übernommen → bereit (Build-Kennung)', async () => {
        const s = setup({ controller: null });
        s.served = NEW_BUILD;
        s.container.controller = { scriptURL: 'sw.js' };
        s.container.emit('controllerchange');
        await flush();
        expect(s.updater.ready.value).toBe(true);
        // Die neue Version kontrolliert die Seite vermutlich schon: ein update() kennt nichts
        // Neueres, also ohne weitere Suche neu laden.
        expect(await s.updater.applyNow('https://x/a')).toBe(true);
        expect(s.registration.updates).toBe(1);
        expect(s.timers).toEqual([]);
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('erste Übernahme, Antwort von build.json erst nach dem Deploy: neue Version trotzdem holen', async () => {
        // Die übernehmende Version ist die eigene; die Kennung kam aber schon vom neuen Build.
        const s = setup({ controller: null });
        s.served = NEW_BUILD;
        s.container.controller = { scriptURL: 'sw.js' };
        s.container.emit('controllerchange');
        await flush();
        const worker = findsNewVersionOnUpdate(s, 1);
        activatesOnMessage(s, worker);
        expect(await s.updater.applyNow('https://x/a')).toBe(true);
        expect(worker.messages).toEqual([SKIP_WAITING_MESSAGE]);
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('Seite ohne Kontrolle: neue Version installiert → Build-Kennung entscheidet', async () => {
        const s = setup({ controller: null });
        s.served = NEW_BUILD;
        installNewVersion(s);
        await flush();
        expect(s.updater.ready.value).toBe(true);
    });

    it('installierte neue Version wartet → bereit, ohne Abfrage über den alten Service Worker', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        installNewVersion(s);
        await flush();
        expect(s.updater.ready.value).toBe(true);
        // Eine Anfrage jetzt liefe über den alten Service Worker und könnte die Aktivierung der
        // neuen Version durch einen anderen Tab anhalten.
        expect(s.buildFetches).toBe(0);
    });

    it('wartet schon beim Start (z. B. aus einer früheren Sitzung) → bereit', () => {
        const container = new FakeContainer({});
        const registration = new FakeRegistration();
        registration.waiting = new FakeWorker('installed');
        const updater = new AppUpdater(bareEnv(container));
        updater.attach(registration);
        expect(updater.ready.value).toBe(true);
    });

    it('ein anderer Tab hat die neue Version aktiviert → bereit', () => {
        const s = setup();
        s.container.emit('controllerchange');
        expect(s.updater.ready.value).toBe(true);
    });

    it('fehlgeschlagene Installation (redundant) → nicht bereit', () => {
        const s = setup();
        const worker = new FakeWorker('installing');
        s.registration.installing = worker;
        s.registration.emit('updatefound');
        worker.setState('redundant');
        expect(s.updater.ready.value).toBe(false);
    });
});

describe('AppUpdater: prüfen', () => {
    it('fragt den Server über registration.update()', async () => {
        const s = setup();
        await s.updater.check();
        expect(s.registration.updates).toBe(1);
    });

    it('offline: keine Prüfung', async () => {
        const s = setup();
        s.online = false;
        await s.updater.check();
        expect(s.registration.updates).toBe(0);
    });

    it('Fehler der Prüfung werden ignoriert', async () => {
        const s = setup();
        s.registration.failUpdate = true;
        await expect(s.updater.check()).resolves.toBeUndefined();
    });

    it('ohne Registrierung (kein Service Worker): nur die Build-Kennung', async () => {
        const updater = new AppUpdater(bareEnv(new FakeContainer(null), NEW_BUILD));
        await updater.check();
        expect(updater.ready.value).toBe(true);
    });

    it('gleiche Build-Kennung beim Server: kein Update', async () => {
        const s = setup();
        await s.updater.check();
        expect(s.buildFetches).toBe(1);
        expect(s.updater.ready.value).toBe(false);
    });

    it('andere Build-Kennung beim Server: Update, auch ohne Zustandswechsel des Service Workers', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        await s.updater.check();
        expect(s.updater.ready.value).toBe(true);
    });

    it('Build-Kennung nicht lesbar (Fehler, alte Version ohne build.json): kein Update', async () => {
        const s = setup();
        s.served = null;
        await s.updater.check();
        expect(s.updater.ready.value).toBe(false);
    });

    it('offline: auch keine Abfrage der Build-Kennung', async () => {
        const s = setup();
        s.online = false;
        s.served = NEW_BUILD;
        await s.updater.check();
        expect(s.buildFetches).toBe(0);
        expect(s.updater.ready.value).toBe(false);
    });
});

describe('AppUpdater: aktualisieren', () => {
    it('ohne neue Version: Seitenwechsel wie immer', async () => {
        const s = setup();
        expect(await s.updater.applyNow('https://x/firefighting/test/suchen')).toBe(false);
        expect(s.navigated).toEqual([]);
    });

    it('Seitenwechsel mit neuer Version: SKIP_WAITING, nach der Übernahme Zieladresse laden', async () => {
        const s = setup();
        const worker = installNewVersion(s);
        activatesOnMessage(s, worker);
        expect(await s.updater.applyNow('https://x/firefighting/test/suchen')).toBe(true);
        expect(worker.messages).toEqual([SKIP_WAITING_MESSAGE]);
        expect(s.navigated).toEqual(['https://x/firefighting/test/suchen']);
        expect(s.storage.getItem(RELOAD_STORAGE_KEY)).toBe(String(s.now));
    });

    it('wird die neue Version noch installiert, erst danach SKIP_WAITING und laden', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        await s.updater.check();
        const worker = new FakeWorker('installing');
        s.registration.installing = worker;
        activatesOnMessage(s, worker);
        const done = s.updater.apply('https://x/a');
        await flush();
        expect(worker.messages).toEqual([]);
        expect(s.navigated).toEqual([]);

        s.registration.installing = null;
        s.registration.waiting = worker;
        worker.setState('installed');
        expect(await done).toBe(true);
        expect(worker.messages).toEqual([SKIP_WAITING_MESSAGE]);
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('neue Version schon aktiv (ein Service Worker hat übernommen): sofort neu laden', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        s.container.emit('controllerchange');
        expect(await s.updater.apply('https://x/a')).toBe(true);
        expect(s.navigated).toEqual(['https://x/a']);
        expect(s.timers).toEqual([]);
    });

    it('ohne Service Worker in Kontrolle (z. B. kein Service Worker): neu laden vom Server', async () => {
        const s = setup({ controller: null });
        s.served = NEW_BUILD;
        await s.updater.check();
        s.registration.failUpdate = true;
        const done = s.updater.apply('https://x/a');
        expect(await runTimers(s)).toEqual(UPDATE_RETRY_DELAYS_MS);
        expect(await done).toBe(true);
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('update() fertig, bevor die neue Version gemeldet wird: auf updatefound warten', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        await s.updater.check();
        const done = s.updater.apply('https://x/a');
        await flush();
        expect(s.navigated).toEqual([]);

        const worker = new FakeWorker('installing');
        activatesOnMessage(s, worker);
        s.registration.installing = worker;
        s.registration.emit('updatefound');
        await flush();
        s.registration.installing = null;
        s.registration.waiting = worker;
        worker.setState('installed');
        expect(await done).toBe(true);
        expect(worker.messages).toEqual([SKIP_WAITING_MESSAGE]);
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('keine neue Version gemeldet: NICHT neu laden (der alte Service Worker lieferte die alte Seite)', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        await s.updater.check();
        const done = s.updater.apply('https://x/a');
        // Prüfung und Seitenwechsel teilen sich EINE Suche: je Pause ein weiteres update().
        expect(await runTimers(s)).toEqual(UPDATE_RETRY_DELAYS_MS);
        expect(s.registration.updates).toBe(2 + UPDATE_RETRY_DELAYS_MS.length);
        expect(await done).toBe(false);
        expect(s.registration.unregistered).toBe(false);
        expect(s.navigated).toEqual([]);
        expect(s.storage.getItem(RELOAD_STORAGE_KEY)).toBeNull();
        expect(s.updater.ready.value).toBe(true);
        expect(s.updater.switching.value).toBe(false);
    });

    it('Aktivierung bleibt stehen (kein controllerchange): Service Worker abmelden, dann neu laden', async () => {
        const s = setup();
        const worker = installNewVersion(s);
        // Der Worker übernimmt die Seite nicht (Chrome hält die Aktivierung an, wenn der alte
        // Service Worker genau beim Wechsel eine Anfrage bekommt).
        const done = s.updater.apply('https://x/a');
        await flush();
        expect(worker.messages).toEqual([SKIP_WAITING_MESSAGE]);
        expect(s.navigated).toEqual([]);
        expect(s.timers.at(-1)?.ms).toBe(TAKEOVER_TIMEOUT_MS);
        s.timers.at(-1)?.callback();
        expect(await done).toBe(true);
        expect(s.registration.unregistered).toBe(true);
        expect(s.navigated).toEqual(['https://x/a']);
        expect(s.storage.getItem(RELOAD_STORAGE_KEY)).toBe(String(s.now));
    });

    it('Aktivierung bleibt stehen und Abmelden scheitert: nicht neu laden, Sperre unberührt', async () => {
        const s = setup();
        installNewVersion(s);
        s.registration.unregister = () => Promise.reject(new Error('SecurityError'));
        const done = s.updater.apply('https://x/a');
        await flush();
        s.timers.at(-1)?.callback();
        expect(await done).toBe(false);
        expect(s.navigated).toEqual([]);
        expect(s.storage.getItem(RELOAD_STORAGE_KEY)).toBeNull();
        expect(s.updater.ready.value).toBe(true);
        expect(s.updater.switching.value).toBe(false);
    });

    it('während des Ladens werden weitere Seitenwechsel abgebrochen, nur einmal geladen', async () => {
        const s = setup();
        const worker = installNewVersion(s);
        const first = s.updater.applyNow('https://x/a');
        expect(await s.updater.applyNow('https://x/b')).toBe(true);
        await flush();
        activatesOnMessage(s, worker);
        worker.onMessage?.();
        expect(await first).toBe(true);
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('nach einem Neuladen keine Schleife: Sperre, Hinweis mit Knopf statt erneutem Neuladen', async () => {
        const s = setup();
        s.storage.setItem(RELOAD_STORAGE_KEY, String(s.now - RELOAD_COOLDOWN_MS + 1));
        installNewVersion(s);
        expect(await s.updater.applyNow('https://x/a')).toBe(false);
        expect(s.navigated).toEqual([]);
        expect(s.updater.autoBlocked.value).toBe(true);
        expect(updateNotice(s.updater)).toBe('blocked');
    });

    it('nach Ablauf der Sperre wieder automatisch', async () => {
        const s = setup();
        s.storage.setItem(RELOAD_STORAGE_KEY, String(s.now - RELOAD_COOLDOWN_MS));
        const worker = installNewVersion(s);
        activatesOnMessage(s, worker);
        expect(await s.updater.applyNow('https://x/a')).toBe(true);
    });

    it('Knopf „Jetzt aktualisieren“ lädt auch während der Sperre', async () => {
        const s = setup();
        s.storage.setItem(RELOAD_STORAGE_KEY, String(s.now));
        const worker = installNewVersion(s);
        activatesOnMessage(s, worker);
        expect(await s.updater.apply('https://x/a')).toBe(true);
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('ohne sessionStorage (gesperrt) weiter ohne Sperre', async () => {
        const throwing = new MemoryStorage();
        throwing.getItem = () => {
            throw new Error('SecurityError');
        };
        throwing.setItem = () => {
            throw new Error('SecurityError');
        };
        const s = setup({ storage: throwing });
        const worker = installNewVersion(s);
        activatesOnMessage(s, worker);
        expect(await s.updater.applyNow('https://x/a')).toBe(true);
    });
});

describe('AppUpdater: update() mit laufender Prüfung zusammengelegt', () => {
    it('Server kennt neue Version, Browser noch nicht: erneut fragen, bis er sie findet', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        findsNewVersionOnUpdate(s, 3);
        await s.updater.check();
        expect(await runTimers(s)).toEqual([500, 1000]);
        expect(s.registration.updates).toBe(3);
        expect(s.registration.waiting).not.toBeNull();
    });

    it('nach den Pausen (0,5 s, 1 s, 2 s, 4 s) Schluss bis zum nächsten Anlass', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        await s.updater.check();
        expect(await runTimers(s)).toEqual(UPDATE_RETRY_DELAYS_MS);
        expect(s.registration.updates).toBe(1 + UPDATE_RETRY_DELAYS_MS.length);
        expect(s.updater.ready.value).toBe(true);

        // Nächster Anlass (z. B. sichtbar): wieder dieselbe Folge, keine Endlosschleife.
        await s.updater.check();
        expect(await runTimers(s)).toEqual(UPDATE_RETRY_DELAYS_MS);
    });

    it('meldet der Browser die neue Version während einer Pause, endet die Suche', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        await s.updater.check();
        s.registration.installing = new FakeWorker('installing');
        s.registration.emit('updatefound');
        await flush();
        expect(s.registration.updates).toBe(1);
        await runTimers(s);
        expect(s.registration.updates).toBe(1);
    });

    it('gleiche Kennung beim Server: kein erneutes Fragen', async () => {
        const s = setup();
        await s.updater.check();
        expect(await runTimers(s)).toEqual([]);
        expect(s.registration.updates).toBe(1);
    });

    it('zwei Prüfungen gleichzeitig: nur eine Suche', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        await Promise.all([s.updater.check(), s.updater.check()]);
        expect(await runTimers(s)).toEqual(UPDATE_RETRY_DELAYS_MS);
        expect(s.registration.updates).toBe(2 + UPDATE_RETRY_DELAYS_MS.length);
    });

    it('Seitenwechsel: erst zusammengelegt, beim erneuten Fragen gefunden → neu laden', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        await s.updater.check();
        await runTimers(s);
        const worker = findsNewVersionOnUpdate(s, s.registration.updates + 2);
        activatesOnMessage(s, worker);
        const done = s.updater.applyNow('https://x/a');
        // Eine Pause, dann gefunden; danach nur noch die Frist der Übernahme (sofort erfüllt).
        expect(await runTimers(s)).toEqual([500, TAKEOVER_TIMEOUT_MS]);
        expect(await done).toBe(true);
        expect(s.navigated).toEqual(['https://x/a']);
    });
});

describe('AppUpdater: Neuladen erst ohne laufende Anfragen', () => {
    /** update() bleibt offen, bis der Test es beendet. */
    function pendingUpdate(s: Setup): () => void {
        let finish: () => void = () => undefined;
        s.registration.update = () => {
            s.registration.updates += 1;
            return new Promise<void>((resolve) => {
                finish = resolve;
            });
        };
        return () => {
            finish();
        };
    }

    it('laufendes update() wird abgewartet, erst dann neu geladen', async () => {
        // WebKit meldet ein vom Neuladen abgebrochenes update() als Fehler (sw.js).
        const s = setup();
        const worker = installNewVersion(s);
        activatesOnMessage(s, worker);
        const finish = pendingUpdate(s);
        void s.updater.check();
        const done = s.updater.applyNow('https://x/a');
        await flush();
        expect(worker.messages).toEqual([SKIP_WAITING_MESSAGE]);
        expect(s.navigated).toEqual([]);
        finish();
        expect(await done).toBe(true);
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('laufende Abfrage von build.json wird ebenso abgewartet', async () => {
        const s = setup();
        const worker = installNewVersion(s);
        activatesOnMessage(s, worker);
        let answer: (build: string | null) => void = () => undefined;
        const updater = new AppUpdater({
            container: s.container,
            storage: null,
            now: () => s.now,
            isOnline: () => true,
            navigate: (url) => s.navigated.push(url),
            setTimeout: (callback, ms) => s.timers.push({ callback, ms }),
            buildId: RUNNING_BUILD,
            fetchBuildId: () =>
                new Promise((resolve) => {
                    answer = resolve;
                }),
        });
        updater.attach(s.registration);
        void updater.check();
        const done = updater.applyNow('https://x/a');
        await flush();
        expect(s.navigated).toEqual([]);
        answer(RUNNING_BUILD);
        expect(await done).toBe(true);
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('hängt die Anfrage, wird nach REQUEST_SETTLE_TIMEOUT_MS trotzdem neu geladen', async () => {
        const s = setup();
        const worker = installNewVersion(s);
        activatesOnMessage(s, worker);
        pendingUpdate(s);
        void s.updater.check();
        const done = s.updater.applyNow('https://x/a');
        await flush();
        expect(s.navigated).toEqual([]);
        expect(s.timers.at(-1)?.ms).toBe(REQUEST_SETTLE_TIMEOUT_MS);
        s.timers.at(-1)?.callback();
        expect(await done).toBe(true);
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('ohne laufende Anfrage sofort neu laden (keine Wartezeit)', async () => {
        const s = setup();
        const worker = installNewVersion(s);
        activatesOnMessage(s, worker);
        await s.updater.check();
        expect(await s.updater.applyNow('https://x/a')).toBe(true);
        expect(s.timers.map((timer) => timer.ms)).not.toContain(REQUEST_SETTLE_TIMEOUT_MS);
    });

    it('nach dem Neuladen keine Anfragen mehr: geplante Wiederholungen und Prüfungen entfallen', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        await s.updater.check();
        expect(s.registration.updates).toBe(1);
        expect(s.timers).toHaveLength(1);
        await s.updater.reloadFromServer('https://x/a');
        expect(s.navigated).toEqual(['https://x/a']);
        // Die schon geplante Wiederholung der Suche fragt nicht mehr.
        await runTimers(s);
        expect(s.registration.updates).toBe(1);
        await s.updater.check();
        expect(s.registration.updates).toBe(1);
        expect(s.buildFetches).toBe(1);
    });
});

describe('AppUpdater: Start mit veralteter Version und „Seite nicht gefunden“', () => {
    const START_URL = 'https://x/firefighting/test/email-bestaetigen#token=abc';

    function settled<T>(promise: Promise<T>): { done: boolean } {
        const state = { done: false };
        void promise.then(() => {
            state.done = true;
        });
        return state;
    }

    it('aktuelle Version: Seite erscheint ohne „Seite wird geladen …“', async () => {
        const s = setup();
        expect(await s.updater.checkOnStart(START_URL)).toBe(false);
        expect(s.updater.loadingPage.value).toBe(false);
    });

    it('neue Version erst beim erneuten Fragen gefunden: lädt die Startadresse samt Fragment', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        const worker = findsNewVersionOnUpdate(s, 3);
        activatesOnMessage(s, worker);
        const start = s.updater.checkOnStart(START_URL);
        await flush();
        expect(s.updater.loadingPage.value).toBe(true);
        await runTimers(s);
        expect(await start).toBe(true);
        expect(worker.messages).toEqual([SKIP_WAITING_MESSAGE]);
        expect(s.navigated).toEqual([START_URL]);
    });

    it('neue Version nicht zu laden: bleibt bei „Seite wird geladen …“, nach rund 30 s Knopf', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        const start = s.updater.checkOnStart(START_URL);
        const state = settled(start);
        // Fristen der Startprüfung (Registrierung, build.json), dann die Pausen bis rund 30 s.
        expect(await runTimers(s)).toEqual([
            START_CHECK_TIMEOUT_MS,
            START_CHECK_TIMEOUT_MS,
            ...START_RETRY_DELAYS_MS,
        ]);
        expect(START_RETRY_DELAYS_MS.reduce((sum, ms) => sum + ms, 0)).toBeGreaterThanOrEqual(
            30_000,
        );
        expect(s.registration.updates).toBe(1 + START_RETRY_DELAYS_MS.length);
        expect(state.done).toBe(false);
        expect(s.updater.loadingPage.value).toBe(true);
        expect(s.updater.reloadNeeded.value).toBe(true);
        expect(s.updater.switching.value).toBe(false);
        expect(s.navigated).toEqual([]);
    });

    it('„Seite nicht gefunden“ mit neuer Version, die nicht lädt: nie „nicht gefunden“, Knopf', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        const state = settled(s.updater.checkBeforeNotFound(START_URL));
        await runTimers(s);
        expect(state.done).toBe(false);
        expect(s.updater.reloadNeeded.value).toBe(true);
    });

    it('„Seite nicht gefunden“ ohne neue Version: Seite erscheint', async () => {
        const s = setup();
        const result = s.updater.checkBeforeNotFound(START_URL);
        await runTimers(s);
        expect(await result).toBe(false);
        expect(s.updater.reloadNeeded.value).toBe(false);
    });

    it('Schleifensperre: kein Warten, Seite erscheint mit dem Hinweis wie bisher', async () => {
        const s = setup();
        s.served = NEW_BUILD;
        s.storage.setItem(RELOAD_STORAGE_KEY, String(s.now));
        expect(await s.updater.checkOnStart(START_URL)).toBe(false);
        expect(s.updater.loadingPage.value).toBe(false);
        expect(s.updater.reloadNeeded.value).toBe(false);
        expect(updateNotice(s.updater)).toBe('blocked');
    });

    it('Knopf „Neu laden“: Service Worker abmelden, Adresse samt Fragment vom Server laden', async () => {
        const s = setup();
        await s.updater.reloadFromServer(START_URL);
        expect(s.registration.unregistered).toBe(true);
        expect(s.navigated).toEqual([START_URL]);
        expect(s.updater.switching.value).toBe(true);
    });
});

describe('updateNotice', () => {
    it('ohne neue Version kein Hinweis, auch mit Eingabe', () => {
        const s = setup();
        unregister.push(registerUnsavedInput(() => true));
        expect(updateNotice(s.updater)).toBeNull();
    });

    it('neue Version ohne Eingabe: kein Hinweis (Update beim nächsten Seitenwechsel)', () => {
        const s = setup();
        installNewVersion(s);
        expect(updateNotice(s.updater)).toBeNull();
    });

    it('neue Version mit angefangenem Formular: Hinweis', () => {
        const s = setup();
        installNewVersion(s);
        unregister.push(registerUnsavedInput(() => true));
        expect(updateNotice(s.updater)).toBe('unsaved');
    });
});

describe('installUpdateGuard', () => {
    async function routerWith(updater: { applyNow: (url: string) => Promise<boolean> }) {
        const component = { template: '<div />' };
        const router = createRouter({
            history: createMemoryHistory('/firefighting/test/'),
            routes: [
                { path: '/', component },
                { path: '/a', component },
                { path: '/b', component },
            ],
        });
        installUpdateGuard(router, updater as unknown as AppUpdater);
        await router.push('/a');
        return router;
    }

    it('nicht beim Start der App', async () => {
        const urls: string[] = [];
        await routerWith({ applyNow: (url) => Promise.resolve((urls.push(url), true)) });
        expect(urls).toEqual([]);
    });

    it('anderer Pfad: neue Version mit der Zieladresse, Seitenwechsel bleibt offen (kein Rücksprung)', async () => {
        const urls: string[] = [];
        let apply = false;
        const router = await routerWith({
            applyNow: (url) => Promise.resolve((urls.push(url), apply)),
        });
        apply = true;
        let settled = false;
        void router.push('/b?x=1').then(() => {
            settled = true;
        });
        await flush();
        expect(urls.at(-1)).toMatch(/\/firefighting\/test\/b\?x=1$/);
        expect(router.currentRoute.value.path).toBe('/a');
        // Ein Abbruch setzte bei Zurück/Vor die Historie zurück und überholte das Neuladen.
        expect(settled).toBe(false);
    });

    it('ohne neue Version normal weiter', async () => {
        const router = await routerWith({ applyNow: () => Promise.resolve(false) });
        await router.push('/b');
        expect(router.currentRoute.value.path).toBe('/b');
    });

    it('gleicher Pfad (z. B. #token=… entfernen): nie neu laden', async () => {
        const urls: string[] = [];
        const router = await routerWith({
            applyNow: (url) => Promise.resolve((urls.push(url), true)),
        });
        await router.replace({ path: '/a', hash: '' });
        await router.replace({ path: '/a', query: { weiter: '/x' } });
        expect(urls).toEqual([]);
    });
});

describe('isChunkLoadError', () => {
    it.each([
        'Failed to fetch dynamically imported module: https://x/assets/a.js',
        'error loading dynamically imported module: https://x/assets/a.js',
        'Importing a module script failed.',
    ])('%s', (message) => {
        expect(isChunkLoadError(new TypeError(message))).toBe(true);
    });

    it('andere Fehler nicht', () => {
        expect(isChunkLoadError(new Error('Netzwerk'))).toBe(false);
        expect(isChunkLoadError('Failed to fetch dynamically imported module')).toBe(false);
    });
});

describe('fetchServedBuildId', () => {
    function fetchReturning(response: Response | Error) {
        const calls: { url: string; init: RequestInit | undefined }[] = [];
        const fn: typeof fetch = (input, init) => {
            calls.push({ url: input instanceof Request ? input.url : input.toString(), init });
            return response instanceof Error ? Promise.reject(response) : Promise.resolve(response);
        };
        return { fn, calls };
    }

    it('liest build.json neben index.html, am Cache vorbei', async () => {
        const { fn, calls } = fetchReturning(new Response('{"build":"abc123"}'));
        expect(await fetchServedBuildId(fn)).toBe('abc123');
        expect(calls[0]?.url).toBe('https://www.prodad.de/firefighting/test/build.json');
        expect(calls[0]?.init?.cache).toBe('no-store');
    });

    it.each([
        ['404', new Response('{"error":"not_found"}', { status: 404 })],
        ['kein JSON', new Response('<html>')],
        ['falsches Feld', new Response('{"version":"x"}')],
        ['leer', new Response('{"build":""}')],
        ['keine Verbindung', new TypeError('offline')],
    ])('%s → null', async (_, response) => {
        expect(await fetchServedBuildId(fetchReturning(response).fn)).toBeNull();
    });
});
