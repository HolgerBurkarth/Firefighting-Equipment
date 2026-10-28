import { afterEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import {
    ACTIVATION_TIMEOUT_MS,
    AppUpdater,
    installUpdateGuard,
    isChunkLoadError,
    RELOAD_COOLDOWN_MS,
    RELOAD_STORAGE_KEY,
    SKIP_WAITING_MESSAGE,
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
    online: boolean;
    now: number;
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
        online: true,
        now: 1_000_000,
    };
    const env: UpdateEnvironment = {
        container,
        storage: options.storage === null ? null : (options.storage ?? storage),
        now: () => state.now,
        isOnline: () => state.online,
        navigate: (url) => state.navigated.push(url),
        setTimeout: (callback, ms) => state.timers.push({ callback, ms }),
    };
    const updater = new AppUpdater(env);
    updater.attach(state.registration);
    return Object.assign(state, { updater });
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

    it('erste Übernahme nach der Installation (clientsClaim) ist kein Update', () => {
        const s = setup({ controller: null });
        s.container.controller = { scriptURL: 'sw.js' };
        s.container.emit('controllerchange');
        expect(s.updater.ready.value).toBe(false);
    });

    it('installierte neue Version wartet → bereit', () => {
        const s = setup();
        installNewVersion(s);
        expect(s.updater.ready.value).toBe(true);
    });

    it('wartet schon beim Start (z. B. aus einer früheren Sitzung) → bereit', () => {
        const container = new FakeContainer({});
        const registration = new FakeRegistration();
        registration.waiting = new FakeWorker('installed');
        const updater = new AppUpdater({
            container,
            storage: null,
            now: () => 0,
            isOnline: () => true,
            navigate: () => undefined,
            setTimeout: () => undefined,
        });
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

    it('ohne Registrierung (kein Service Worker) nichts', async () => {
        const updater = new AppUpdater({
            container: new FakeContainer(null),
            storage: null,
            now: () => 0,
            isOnline: () => true,
            navigate: () => undefined,
            setTimeout: () => undefined,
        });
        await expect(updater.check()).resolves.toBeUndefined();
    });
});

describe('AppUpdater: aktualisieren', () => {
    it('ohne neue Version: Seitenwechsel wie immer', () => {
        const s = setup();
        expect(s.updater.applyOnNavigation('https://x/firefighting/test/suchen')).toBe(false);
        expect(s.navigated).toEqual([]);
    });

    it('Seitenwechsel mit neuer Version: SKIP_WAITING, nach der Übernahme Zieladresse laden', async () => {
        const s = setup();
        const worker = installNewVersion(s);
        activatesOnMessage(s, worker);
        expect(s.updater.applyOnNavigation('https://x/firefighting/test/suchen')).toBe(true);
        await Promise.resolve();
        expect(worker.messages).toEqual([SKIP_WAITING_MESSAGE]);
        expect(s.navigated).toEqual(['https://x/firefighting/test/suchen']);
        expect(s.storage.getItem(RELOAD_STORAGE_KEY)).toBe(String(s.now));
    });

    it('übernimmt der Worker nicht, wird nach der Frist trotzdem geladen', async () => {
        const s = setup();
        installNewVersion(s);
        const done = s.updater.apply('https://x/a');
        expect(s.navigated).toEqual([]);
        expect(s.timers.map((t) => t.ms)).toEqual([ACTIVATION_TIMEOUT_MS]);
        s.timers[0]?.callback();
        await done;
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('während des Ladens werden weitere Seitenwechsel abgebrochen, nur einmal geladen', async () => {
        const s = setup();
        installNewVersion(s);
        expect(s.updater.applyOnNavigation('https://x/a')).toBe(true);
        expect(s.updater.applyOnNavigation('https://x/b')).toBe(true);
        s.timers[0]?.callback();
        await Promise.resolve();
        await Promise.resolve();
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('nach einem Neuladen keine Schleife: Sperre, Hinweis mit Knopf statt erneutem Neuladen', () => {
        const s = setup();
        s.storage.setItem(RELOAD_STORAGE_KEY, String(s.now - RELOAD_COOLDOWN_MS + 1));
        installNewVersion(s);
        expect(s.updater.applyOnNavigation('https://x/a')).toBe(false);
        expect(s.navigated).toEqual([]);
        expect(s.updater.autoBlocked.value).toBe(true);
        expect(updateNotice(s.updater)).toBe('blocked');
    });

    it('nach Ablauf der Sperre wieder automatisch', () => {
        const s = setup();
        s.storage.setItem(RELOAD_STORAGE_KEY, String(s.now - RELOAD_COOLDOWN_MS));
        installNewVersion(s);
        expect(s.updater.applyOnNavigation('https://x/a')).toBe(true);
    });

    it('Knopf „Jetzt aktualisieren“ lädt auch während der Sperre', async () => {
        const s = setup();
        s.storage.setItem(RELOAD_STORAGE_KEY, String(s.now));
        const worker = installNewVersion(s);
        activatesOnMessage(s, worker);
        await s.updater.apply('https://x/a');
        expect(s.navigated).toEqual(['https://x/a']);
    });

    it('ohne sessionStorage (gesperrt) weiter ohne Sperre', () => {
        const throwing = new MemoryStorage();
        throwing.getItem = () => {
            throw new Error('SecurityError');
        };
        throwing.setItem = () => {
            throw new Error('SecurityError');
        };
        const s = setup({ storage: throwing });
        installNewVersion(s);
        expect(s.updater.applyOnNavigation('https://x/a')).toBe(true);
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
    async function routerWith(updater: { applyOnNavigation: (url: string) => boolean }) {
        const component = { template: '<div />' };
        const router = createRouter({
            history: createMemoryHistory('/firefighting/test/'),
            routes: [
                { path: '/', component },
                { path: '/a', component },
                { path: '/b', component },
            ],
        });
        installUpdateGuard(router, updater as AppUpdater);
        await router.push('/a');
        return router;
    }

    it('nicht beim Start der App', async () => {
        const urls: string[] = [];
        await routerWith({ applyOnNavigation: (url) => (urls.push(url), true) });
        expect(urls).toEqual([]);
    });

    it('anderer Pfad: neue Version mit der Zieladresse, Navigation abgebrochen', async () => {
        const urls: string[] = [];
        let apply = false;
        const router = await routerWith({ applyOnNavigation: (url) => (urls.push(url), apply) });
        apply = true;
        await router.push('/b?x=1');
        expect(urls.at(-1)).toMatch(/\/firefighting\/test\/b\?x=1$/);
        expect(router.currentRoute.value.path).toBe('/a');
    });

    it('ohne neue Version normal weiter', async () => {
        const router = await routerWith({ applyOnNavigation: () => false });
        await router.push('/b');
        expect(router.currentRoute.value.path).toBe('/b');
    });

    it('gleicher Pfad (z. B. #token=… entfernen): nie neu laden', async () => {
        const urls: string[] = [];
        const router = await routerWith({ applyOnNavigation: (url) => (urls.push(url), true) });
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
