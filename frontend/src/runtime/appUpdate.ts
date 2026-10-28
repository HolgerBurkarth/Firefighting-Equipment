import { shallowRef } from 'vue';
import { START_LOCATION, type Router } from 'vue-router';
import { hasUnsavedInput } from './unsavedInput';

/**
 * Updates der App (Konzept: KONZEPT.md „Updates der App“). Der Service Worker einer neuen Version
 * wartet (kein skipWaiting im Build), bis die App ihn aktiviert: beim nächsten Seitenwechsel oder
 * per Knopf „Jetzt aktualisieren“, danach lädt sie die Seite neu. Ist ein Formular angefangen
 * (unsavedInput.ts), erscheint stattdessen ein Hinweis; Eingaben gehen nie verloren.
 *
 * „Neue Version da“ folgt aus ZWEI Quellen: dem Service Worker (eine neue Version wartet) und dem
 * Vergleich der Build-Kennung des Servers (build.json) mit der eigenen (BUILD_ID). Der Vergleich
 * erkennt auch Fälle, in denen der neue Service Worker schon aktiv ist, die Seite aber noch die alte
 * Version zeigt (z. B. per Strg+F5 geladen und dann übernommen, oder Deploy beim ersten Besuch).
 */

/** Regelmäßige Prüfung, solange die App offen ist. */
export const UPDATE_CHECK_INTERVAL_MS = 60 * 60 * 1000;
/** Nach einem automatischen Neuladen wird so lange nicht erneut automatisch neu geladen. */
export const RELOAD_COOLDOWN_MS = 10 * 60 * 1000;
/** So lange wird auf die Aktivierung des neuen Service Workers gewartet, dann trotzdem geladen. */
export const ACTIVATION_TIMEOUT_MS = 5000;
/** Nachricht an den wartenden Service Worker (Workbox generateSW ohne skipWaiting). */
export const SKIP_WAITING_MESSAGE = { type: 'SKIP_WAITING' } as const;
export const RELOAD_STORAGE_KEY = 'ff-update-reloaded-at';

type Listener = (event: Event) => void;

export interface UpdateWorker {
    readonly state: string;
    postMessage(message: unknown): void;
    addEventListener(type: 'statechange', listener: Listener): void;
}

export interface UpdateRegistration {
    readonly waiting: UpdateWorker | null;
    readonly installing: UpdateWorker | null;
    update(): Promise<unknown>;
    addEventListener(type: 'updatefound', listener: Listener): void;
}

export interface UpdateContainer {
    readonly controller: unknown;
    addEventListener(type: 'controllerchange', listener: Listener): void;
    removeEventListener(type: 'controllerchange', listener: Listener): void;
}

export interface UpdateEnvironment {
    readonly container: UpdateContainer;
    /** sessionStorage (je Tab); null oder Fehler beim Zugriff = ohne Sperre gegen Schleifen. */
    readonly storage: Pick<Storage, 'getItem' | 'setItem'> | null;
    readonly now: () => number;
    readonly isOnline: () => boolean;
    /** Lädt die Adresse vollständig neu (window.location.assign). */
    readonly navigate: (url: string) => void;
    readonly setTimeout: (callback: () => void, ms: number) => unknown;
    /** Kennung der laufenden Version (BUILD_ID). */
    readonly buildId: string;
    /** Kennung der ausgelieferten Version (build.json, ohne Cache); null bei jedem Fehler. */
    readonly fetchBuildId: () => Promise<string | null>;
}

export class AppUpdater {
    /** Eine neue Version ist da (wartet, hat schon übernommen oder der Server liefert sie aus). */
    readonly ready = shallowRef(false);
    /** Automatisches Neuladen gesperrt, weil kurz zuvor schon neu geladen wurde (Schleife). */
    readonly autoBlocked = shallowRef(false);
    private registration: UpdateRegistration | null = null;
    private applying = false;

    constructor(private readonly env: UpdateEnvironment) {
        // Hat ein anderer Tab die neue Version aktiviert, läuft hier noch der alte Code unter dem
        // neuen Service Worker: dann ebenfalls neu laden (beim nächsten Seitenwechsel). Übernimmt
        // ein Service Worker eine Seite ohne Kontrolle (erste Installation, Strg+F5), entscheidet
        // der Vergleich der Build-Kennung.
        let hadController = env.container.controller !== null;
        env.container.addEventListener('controllerchange', () => {
            if (this.applying) {
                return;
            }
            if (hadController) {
                this.markReady();
            } else {
                void this.compareBuild();
            }
            hadController = true;
        });
    }

    /** Verfolgt die Registrierung des Service Workers. */
    attach(registration: UpdateRegistration): void {
        this.registration = registration;
        if (registration.waiting !== null) {
            this.markReady();
        }
        registration.addEventListener('updatefound', () => {
            const worker = registration.installing;
            worker?.addEventListener('statechange', () => {
                if (worker.state === 'installed') {
                    this.markReady();
                    void this.compareBuild();
                }
            });
        });
    }

    /**
     * Fragt nach einer neuen Version: Service Worker (registration.update) und Build-Kennung des
     * Servers. Offline nicht, Fehler werden ignoriert. Ohne Service Worker nur die Kennung.
     */
    async check(): Promise<void> {
        if (!this.env.isOnline()) {
            return;
        }
        if (this.registration !== null) {
            try {
                await this.registration.update();
            } catch {
                // Keine Verbindung oder Server gestört: beim nächsten Anlass erneut.
            }
        }
        await this.compareBuild();
    }

    /** Liefert der Server eine andere Version als die laufende, ist ein Update da. */
    private async compareBuild(): Promise<void> {
        if (!this.env.isOnline()) {
            return;
        }
        const served = await this.env.fetchBuildId();
        if (served !== null && served !== this.env.buildId) {
            this.ready.value = true;
        }
    }

    /**
     * Beim Seitenwechsel: true = die App lädt gerade die neue Version (Navigation abbrechen).
     * Auch mit angefangenem Formular, denn die Seite wird ohnehin verlassen.
     */
    applyOnNavigation(url: string): boolean {
        if (this.applying) {
            return true;
        }
        if (!this.ready.value) {
            return false;
        }
        if (this.recentlyReloaded()) {
            this.autoBlocked.value = true;
            return false;
        }
        void this.apply(url);
        return true;
    }

    /** Aktiviert die neue Version und lädt `url` (Knopf „Jetzt aktualisieren“, Seitenwechsel). */
    async apply(url: string): Promise<void> {
        if (this.applying) {
            return;
        }
        this.applying = true;
        this.rememberReload();
        // Wird die neue Version gerade noch installiert, erst darauf warten: Sonst lieferte der alte
        // Service Worker beim Neuladen wieder die alte Seite.
        await this.installed();
        const waiting = this.registration?.waiting ?? null;
        if (waiting !== null) {
            await this.activate(waiting);
        }
        this.env.navigate(url);
    }

    private markReady(): void {
        // Erste Installation (noch kein Service Worker in Kontrolle) ist kein Update.
        if (this.env.container.controller === null) {
            return;
        }
        this.ready.value = true;
    }

    private installed(): Promise<void> {
        const installing = this.registration?.installing ?? null;
        if (installing === null) {
            return Promise.resolve();
        }
        return new Promise((resolve) => {
            installing.addEventListener('statechange', () => {
                if (installing.state !== 'installing') {
                    resolve();
                }
            });
            this.env.setTimeout(resolve, ACTIVATION_TIMEOUT_MS);
        });
    }

    private activate(waiting: UpdateWorker): Promise<void> {
        return new Promise((resolve) => {
            const done = (): void => {
                this.env.container.removeEventListener('controllerchange', done);
                resolve();
            };
            this.env.container.addEventListener('controllerchange', done);
            this.env.setTimeout(done, ACTIVATION_TIMEOUT_MS);
            waiting.postMessage(SKIP_WAITING_MESSAGE);
        });
    }

    private recentlyReloaded(): boolean {
        let value: string | null = null;
        try {
            value = this.env.storage?.getItem(RELOAD_STORAGE_KEY) ?? null;
        } catch {
            return false;
        }
        const at = Number(value);
        return value !== null && Number.isFinite(at) && this.env.now() - at < RELOAD_COOLDOWN_MS;
    }

    private rememberReload(): void {
        try {
            this.env.storage?.setItem(RELOAD_STORAGE_KEY, String(this.env.now()));
        } catch {
            // Ohne Speicher keine Sperre; das Neuladen geschieht trotzdem nur bei einem Update.
        }
    }
}

/** Hinweis oben: neue Version da, aber gerade nicht automatisch (Eingabe oder Schleifensperre). */
export function updateNotice(updater: AppUpdater): 'unsaved' | 'blocked' | null {
    if (!updater.ready.value) {
        return null;
    }
    if (hasUnsavedInput.value) {
        return 'unsaved';
    }
    return updater.autoBlocked.value ? 'blocked' : null;
}

/**
 * Beim Seitenwechsel auf eine andere Seite (anderer Pfad, nicht beim Start und nicht beim Entfernen
 * eines Fragments wie #token=…) die neue Version laden statt in der alten App weiterzublättern.
 */
export function installUpdateGuard(router: Router, updater: AppUpdater): void {
    router.beforeEach((to, from) => {
        if (from === START_LOCATION || to.path === from.path) {
            return true;
        }
        const url = new URL(router.resolve(to).href, window.location.href).href;
        return !updater.applyOnNavigation(url);
    });
}

/** Fehlertexte der Browser, wenn ein Teil der App (Chunk) nicht mehr geladen werden kann. */
const CHUNK_ERROR =
    /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed/i;

export function isChunkLoadError(error: unknown): boolean {
    return error instanceof Error && CHUNK_ERROR.test(error.message);
}

/**
 * Prüfung beim Start, wenn die App wieder sichtbar wird, und stündlich. Seiten, deren Teile nach
 * einem Deploy fehlen (alte App ohne Service Worker), werden einmal neu geladen.
 */
export function startUpdateChecks(router: Router, updater: AppUpdater): void {
    void updater.check();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void updater.check();
        }
    });
    window.setInterval(() => {
        void updater.check();
    }, UPDATE_CHECK_INTERVAL_MS);
    router.onError((error, to) => {
        if (isChunkLoadError(error)) {
            const url = new URL(router.resolve(to).href, window.location.href).href;
            updater.ready.value = true;
            updater.applyOnNavigation(url);
        }
    });
}

const NO_SERVICE_WORKER: UpdateContainer = {
    controller: null,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
};

/** build.json des Builds (neben index.html), am HTTP-Cache vorbei. */
export async function fetchServedBuildId(fetchFn: typeof fetch = fetch): Promise<string | null> {
    try {
        const response = await fetchFn(new URL('build.json', document.baseURI).href, {
            cache: 'no-store',
            credentials: 'same-origin',
        });
        if (!response.ok) {
            return null;
        }
        const json: unknown = await response.json();
        const build =
            typeof json === 'object' && json !== null && 'build' in json ? json.build : null;
        return typeof build === 'string' && build !== '' ? build : null;
    } catch {
        return null;
    }
}

export function browserUpdateEnvironment(buildId: string): UpdateEnvironment {
    let storage: Storage | null = null;
    try {
        storage = window.sessionStorage;
    } catch {
        storage = null;
    }
    return {
        // Ohne Service Worker (alter Browser, Entwicklung) bleibt nur das Neuladen bei fehlenden Teilen.
        container: 'serviceWorker' in navigator ? navigator.serviceWorker : NO_SERVICE_WORKER,
        storage,
        now: () => Date.now(),
        isOnline: () => navigator.onLine,
        navigate: (url) => {
            window.location.assign(url);
        },
        setTimeout: (callback, ms) => window.setTimeout(callback, ms),
        buildId,
        fetchBuildId: () => fetchServedBuildId(),
    };
}
