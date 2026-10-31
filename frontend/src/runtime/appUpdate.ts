import { shallowRef, type InjectionKey } from 'vue';
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
/**
 * So lange wartet ein Seitenwechsel auf die Installation der neuen Version (danach weiter mit der
 * laufenden). Beim Start und vor „Seite nicht gefunden“ gilt START_LOAD_TIMEOUT_MS.
 */
export const ACTIVATION_TIMEOUT_MS = 5000;
/**
 * So lange wird nach SKIP_WAITING auf die Übernahme gewartet (gemessen: rund 10 ms). Bleibt sie aus,
 * hängt die Aktivierung; dann meldet die App den Service Worker ab und lädt neu (siehe apply).
 */
export const TAKEOVER_TIMEOUT_MS = 2000;
/**
 * Höchstens so lange wartet der Start (und „Seite nicht gefunden“) auf die Build-Kennung des Servers;
 * danach geht es ohne Ergebnis weiter (langsame Verbindung).
 */
export const START_CHECK_TIMEOUT_MS = 2000;
/**
 * Meldet build.json eine neue Version, der Browser aber keinen neuen Service Worker, fragt die App
 * nach diesen Pausen erneut (registration.update), danach erst wieder bei den normalen Anlässen.
 * Grund: Der Browser legt ein update() mit einer noch laufenden Prüfung zusammen (Service-Worker-
 * Standard, gleichwertige Jobs). Begann diese vor dem Deploy, meldet sie die alte sw.js, und das
 * update() danach findet nichts.
 */
export const UPDATE_RETRY_DELAYS_MS: readonly number[] = [500, 1000, 2000, 4000];
/**
 * Beim Start mit veralteter Version und vor „Seite nicht gefunden“ weiter bis rund 30 s; so lange
 * bleibt „Seite wird geladen …“ stehen, danach der Hinweis mit dem Knopf „Neu laden“.
 */
export const START_RETRY_DELAYS_MS: readonly number[] = [...UPDATE_RETRY_DELAYS_MS, 8000, 15000];
/**
 * Start und „Seite nicht gefunden“: Frist ab Beginn für Suche UND Installation des neuen Service
 * Workers (= Summe der Pausen, rund 30 s). Live dauert die Installation (alle Dateien vorab laden)
 * länger als ACTIVATION_TIMEOUT_MS; mit dieser Frist gab der Start nach rund 6 s auf, obwohl der neue
 * Service Worker kurz danach bereitstand (31.10.2026).
 */
export const START_LOAD_TIMEOUT_MS = START_RETRY_DELAYS_MS.reduce((sum, ms) => sum + ms, 0);
/**
 * Vor dem Neuladen wird höchstens so lange auf laufende Anfragen (update(), build.json) gewartet.
 * Bricht das Neuladen eine Anfrage an sw.js ab, meldet WebKit einen Fehler („… due to access control
 * checks“).
 */
export const REQUEST_SETTLE_TIMEOUT_MS = 1000;
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
    unregister(): Promise<boolean>;
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
    /** Die neue Version wird gerade aktiviert (Hinweis „Neue Version wird geladen …“). */
    readonly switching = shallowRef(false);
    /** Start mit veralteter Version: Die erste Seite wartet auf die neue („Seite wird geladen …“). */
    readonly loadingPage = shallowRef(false);
    /** Die neue Version ließ sich beim Start nicht laden: Hinweis mit Knopf „Neu laden“. */
    readonly reloadNeeded = shallowRef(false);
    private registration: UpdateRegistration | null = null;
    private applying = false;
    /**
     * Ein Service Worker einer neueren Version hat diese Seite übernommen (sie läuft noch mit altem
     * Code). Nicht bei der ersten Übernahme durch die eigene Version (clientsClaim nach der
     * Installation): Deren Service Worker liefert beim Neuladen die alte Seite.
     */
    private controllerChanged = false;
    /**
     * Erste Übernahme, und der Server lieferte da schon eine andere Version (Strg+F5, dann Deploy):
     * Die übernehmende Version ist vermutlich die neue, sicher ist es nicht (die Antwort kann erst
     * nach einem Deploy kommen, der nach der Installation der eigenen Version lag).
     */
    private claimedByNewer = false;
    /** Laufende Suche nach dem neuen Service Worker (gemeinsam für alle Aufrufer). */
    private search: Promise<boolean> | null = null;
    private searchDelays: readonly number[] = [];
    /** Die App lädt neu: keine Anfragen mehr, auch keine geplanten Wiederholungen der Suche. */
    private reloading = false;
    /** Laufende Anfragen an den Server (update(), build.json); vor dem Neuladen abgewartet. */
    private readonly requests = new Set<Promise<unknown>>();

    constructor(private readonly env: UpdateEnvironment) {
        // Hat ein anderer Tab die neue Version aktiviert, läuft hier noch der alte Code unter dem
        // neuen Service Worker: dann ebenfalls neu laden (beim nächsten Seitenwechsel). Übernimmt
        // ein Service Worker eine Seite ohne Kontrolle (erste Installation, Strg+F5), entscheidet
        // der Vergleich der Build-Kennung.
        let hadController = env.container.controller !== null;
        env.container.addEventListener('controllerchange', () => {
            const replaced = hadController;
            hadController = true;
            // Ersetzt oder gerade selbst aktiviert: Es übernimmt die neue Version.
            if (replaced || this.applying) {
                this.controllerChanged = true;
                if (!this.applying) {
                    this.markReady();
                }
                return;
            }
            // Erste Übernahme: meist die eigene Version nach der Installation; vermutlich neuer,
            // wenn der Server schon eine andere Version ausliefert (siehe claimedByNewer).
            void this.compareBuild().then((newer) => {
                this.claimedByNewer = newer;
            });
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
                if (worker.state !== 'installed') {
                    return;
                }
                // Unter einem Service Worker heißt eine wartende neue Version schon „Update“. Hier
                // NICHT build.json fragen: Die Anfrage liefe über den alten Service Worker, und
                // aktiviert ein anderer Tab genau jetzt die neue Version, startet Chrome den alten
                // dafür neu und die Aktivierung bleibt stehen (Ursache sporadisch alter Seiten).
                // Nur ein ANDERER Service Worker in Kontrolle zählt: WebKit meldet bei der ersten
                // Installation den neuen schon als controller, während er noch „installed“ ist.
                const controller = this.env.container.controller;
                if (controller !== null && controller !== worker) {
                    this.markReady();
                } else {
                    void this.compareBuild();
                }
            });
        });
    }

    /**
     * Fragt nach einer neuen Version: Service Worker (registration.update) und Build-Kennung des
     * Servers, beides gleichzeitig. Offline nicht, Fehler werden ignoriert. Ohne Service Worker nur
     * die Kennung. Kennt der Server eine neue Version, der Browser aber noch keinen neuen Service
     * Worker, fragt die App im Hintergrund erneut (UPDATE_RETRY_DELAYS_MS).
     */
    async check(): Promise<void> {
        if (this.reloading || !this.env.isOnline()) {
            return;
        }
        const registration = this.registration;
        await Promise.all([
            registration === null ? undefined : this.updateQuietly(registration),
            this.compareBuild(),
        ]);
        if (
            registration !== null &&
            this.ready.value &&
            this.env.container.controller !== null &&
            !this.claimedByNewer &&
            !this.hasNewWorker(registration)
        ) {
            void this.findNewWorker(registration, UPDATE_RETRY_DELAYS_MS);
        }
    }

    /**
     * Beim Start, bevor eine Seite angezeigt wird: Kommt die Seite aus dem Service Worker (also
     * womöglich veraltet) und liefert der Server eine andere Version, sofort einmal neu laden, und
     * zwar mit der vollständigen Adresse samt #token bzw. #code (noch hat keine Seite das Fragment
     * entfernt). Schleifensperre wie beim Seitenwechsel. true = die App lädt neu.
     */
    async checkOnStart(
        url: string,
        registered: Promise<unknown> = Promise.resolve(),
    ): Promise<boolean> {
        // Registrierung abwarten (für das Aktivieren der neuen Version), aber nie unbegrenzt.
        await this.withTimeout(registered);
        if (this.env.container.controller === null || !this.env.isOnline()) {
            return false;
        }
        const served = await this.withTimeout(this.track(this.env.fetchBuildId()));
        if (served === null || served === this.env.buildId) {
            return false;
        }
        this.ready.value = true;
        this.loadingPage.value = true;
        const reloading = await this.loadNewVersion(url);
        this.loadingPage.value = false;
        return reloading;
    }

    /**
     * Vor „Seite nicht gefunden“: Vielleicht kennt erst die neue Version die Seite. Ist eine da und
     * nichts ungespeichert, sofort neu laden (Schleifensperre wie sonst). true = die App lädt neu.
     */
    async checkBeforeNotFound(url: string): Promise<boolean> {
        await this.withTimeout(this.check());
        if (hasUnsavedInput.value) {
            return false;
        }
        return this.loadNewVersion(url);
    }

    /**
     * Knopf „Neu laden“ (die neue Version ließ sich nicht aktivieren): Service Worker abmelden, damit
     * die Seite vom Server kommt (neue Version, registriert ihn neu), und `url` samt #token bzw.
     * #code neu laden.
     */
    async reloadFromServer(url: string): Promise<void> {
        this.applying = true;
        this.switching.value = true;
        await this.unregister();
        await this.reload(url);
    }

    /**
     * Lädt `url` neu. Vorher enden alle Anfragen: Ab jetzt beginnt keine neue (auch keine geplante
     * Wiederholung der Suche, keine Prüfung), laufende werden höchstens REQUEST_SETTLE_TIMEOUT_MS
     * abgewartet. Sonst bräche das Neuladen z. B. ein update() ab, und WebKit meldet das als Fehler.
     */
    private async reload(url: string): Promise<void> {
        this.reloading = true;
        if (this.requests.size > 0) {
            await Promise.race([
                Promise.allSettled(this.requests),
                new Promise<void>((resolve) => {
                    this.env.setTimeout(resolve, REQUEST_SETTLE_TIMEOUT_MS);
                }),
            ]);
        }
        this.rememberReload();
        this.env.navigate(url);
    }

    /** Merkt sich die Anfrage, bis sie endet (siehe reload). */
    private track<T>(request: Promise<T>): Promise<T> {
        const tracked = request.finally(() => {
            this.requests.delete(tracked);
        });
        this.requests.add(tracked);
        return tracked;
    }

    /**
     * Start und „Seite nicht gefunden“: neue Version laden, dabei bis rund 30 s nach dem neuen
     * Service Worker fragen (START_RETRY_DELAYS_MS). true = die App lädt neu; false = keine neue
     * Version (oder Schleifensperre), die Seite erscheint. Gelingt es trotz neuer Version nicht,
     * bleibt die Seite beim Laden (das Promise endet nie, nie kurz „nicht gefunden“) und
     * `reloadNeeded` zeigt den Knopf „Neu laden“.
     */
    private async loadNewVersion(url: string): Promise<boolean> {
        const deadline = this.env.now() + START_LOAD_TIMEOUT_MS;
        if (await this.applyNow(url, START_RETRY_DELAYS_MS, deadline)) {
            return true;
        }
        if (!this.ready.value || this.autoBlocked.value) {
            return false;
        }
        this.reloadNeeded.value = true;
        return new Promise<never>(() => undefined);
    }

    private withTimeout<T>(promise: Promise<T>): Promise<T | null> {
        return Promise.race([
            promise,
            new Promise<null>((resolve) => {
                this.env.setTimeout(() => {
                    resolve(null);
                }, START_CHECK_TIMEOUT_MS);
            }),
        ]);
    }

    /** Liefert der Server eine andere Version als die laufende, ist ein Update da. true = neuer. */
    private async compareBuild(): Promise<boolean> {
        // Während des Neuladens nicht mehr fragen (der Browser bricht die Anfrage ohnehin ab).
        if (this.applying || !this.env.isOnline()) {
            return false;
        }
        const served = await this.track(this.env.fetchBuildId());
        if (served === null || served === this.env.buildId) {
            return false;
        }
        this.ready.value = true;
        return true;
    }

    /**
     * Beim Seitenwechsel (auch mit angefangenem Formular, die Seite wird ohnehin verlassen), beim
     * Start und vor „Seite nicht gefunden“: neue Version laden, sofern eine da ist und die
     * Schleifensperre nicht greift. true = die App lädt neu (Navigation abbrechen); false = weiter
     * mit der laufenden Version (auch wenn die neue gerade nicht aktiviert werden konnte).
     * `installDeadline` (wie env.now): bis dahin auf die Installation warten, sonst
     * ACTIVATION_TIMEOUT_MS.
     */
    async applyNow(
        url: string,
        retryDelays: readonly number[] = UPDATE_RETRY_DELAYS_MS,
        installDeadline: number | null = null,
    ): Promise<boolean> {
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
        return this.apply(url, retryDelays, installDeadline);
    }

    /**
     * Aktiviert die neue Version und lädt `url` (Knopf „Jetzt aktualisieren“, Seitenwechsel).
     * Neu geladen wird NUR, wenn danach wirklich die neue Version ausgeliefert wird: der neue Service
     * Worker kontrolliert die Seite (controllerchange) oder es gibt keinen. Sonst lieferte der alte
     * wieder die alte Seite, und die Schleifensperre verhinderte jeden weiteren Versuch. Ohne
     * Neuladen bleibt `ready` gesetzt, die Sperre unberührt; aktiviert sich die neue Version später,
     * folgt das Update nach den üblichen Regeln. true = die App lädt neu.
     */
    async apply(
        url: string,
        retryDelays: readonly number[] = UPDATE_RETRY_DELAYS_MS,
        installDeadline: number | null = null,
    ): Promise<boolean> {
        if (this.applying) {
            return true;
        }
        this.applying = true;
        this.switching.value = true;
        // Kennt der Service Worker die neue Version noch nicht (nur die Build-Kennung zeigte sie),
        // erst danach fragen. Wird sie gerade installiert, darauf warten: Sonst lieferte der alte
        // Service Worker beim Neuladen wieder die alte Seite.
        const registration = this.registration;
        if (registration !== null && !this.hasNewWorker(registration)) {
            // Scheitert update() (keine Verbindung), geht es trotzdem weiter; die Sperre greift.
            await this.updateQuietly(registration);
            // update() kann ohne neue Version enden, wenn der Browser es mit einer laufenden Prüfung
            // zusammenlegt (siehe UPDATE_RETRY_DELAYS_MS). Dann erneut fragen; sonst lieferte der
            // alte Service Worker beim Neuladen wieder die alte Seite. Nicht, wenn die übernehmende
            // Version vermutlich schon die neue ist (ein update() genügt, um es zu prüfen).
            if (!this.claimedByNewer) {
                await this.findNewWorker(registration, retryDelays);
            }
        }
        // Sobald die Installation endet, geht es weiter (statechange); eine wartende Version wird
        // also sofort aktiviert, nicht erst nach Ablauf der Frist.
        await this.installed(
            installDeadline === null
                ? ACTIVATION_TIMEOUT_MS
                : Math.max(ACTIVATION_TIMEOUT_MS, installDeadline - this.env.now()),
        );
        const waiting = this.registration?.waiting ?? null;
        if (waiting !== null) {
            await this.activate(waiting);
        }
        // Kennt der Browser nach einer Übernahme bei schon abweichender Kennung keine neuere Version,
        // liefert die übernehmende die neue Seite.
        const takenOver = this.controllerChanged || (this.claimedByNewer && waiting === null);
        if (this.env.container.controller !== null && !takenOver) {
            // Die neue Version wartet, übernimmt aber nicht: Chrome hält die Aktivierung an, wenn der
            // alte Service Worker genau beim Wechsel eine Anfrage bekommt (anderer Tab, Schrift …),
            // und setzt sie nicht zuverlässig fort. Dann den Service Worker abmelden: Die Seite kommt
            // beim Neuladen vom Server (neue Version) und registriert ihn neu. Ohne wartende neue
            // Version (z. B. Download gescheitert) nicht neu laden.
            if (waiting === null || !(await this.unregister())) {
                this.applying = false;
                this.switching.value = false;
                return false;
            }
        }
        await this.reload(url);
        return true;
    }

    private async unregister(): Promise<boolean> {
        try {
            return (await this.registration?.unregister()) ?? false;
        } catch {
            return false;
        }
    }

    private markReady(): void {
        // Erste Installation (noch kein Service Worker in Kontrolle) ist kein Update.
        if (this.env.container.controller === null) {
            return;
        }
        this.ready.value = true;
    }

    /** Neuer Service Worker gefunden (wird installiert, wartet oder hat schon übernommen). */
    private hasNewWorker(registration: UpdateRegistration): boolean {
        return (
            registration.waiting !== null ||
            registration.installing !== null ||
            this.controllerChanged
        );
    }

    private async updateQuietly(registration: UpdateRegistration): Promise<void> {
        if (this.reloading) {
            return;
        }
        try {
            await this.track(registration.update());
        } catch {
            // Keine Verbindung oder Server gestört: beim nächsten Anlass erneut.
        }
    }

    /**
     * Wartet je Pause aus `delays` auf den neuen Service Worker (updatefound) und fragt danach erneut.
     * Läuft schon eine Suche, gilt sie für alle; längere Pausenlisten verlängern sie. true = gefunden.
     */
    private findNewWorker(
        registration: UpdateRegistration,
        delays: readonly number[],
    ): Promise<boolean> {
        if (delays.length > this.searchDelays.length) {
            this.searchDelays = delays;
        }
        this.search ??= this.runSearch(registration).finally(() => {
            this.search = null;
            this.searchDelays = [];
        });
        return this.search;
    }

    private async runSearch(registration: UpdateRegistration): Promise<boolean> {
        for (let attempt = 0; ; attempt += 1) {
            if (this.hasNewWorker(registration)) {
                return true;
            }
            const delay = this.searchDelays[attempt];
            if (delay === undefined || this.reloading) {
                return false;
            }
            await this.newWorker(registration, delay);
            if (this.hasNewWorker(registration)) {
                return true;
            }
            // Lädt die App inzwischen neu, fragt updateQuietly nicht mehr (siehe reload).
            await this.updateQuietly(registration);
        }
    }

    /** Fertig bei updatefound oder nach `ms`. */
    private newWorker(registration: UpdateRegistration, ms: number): Promise<void> {
        return new Promise((resolve) => {
            registration.addEventListener('updatefound', () => {
                resolve();
            });
            this.env.setTimeout(resolve, ms);
        });
    }

    /** Fertig, wenn die Installation endet (installiert oder gescheitert), spätestens nach `ms`. */
    private installed(ms: number): Promise<void> {
        const installing = this.registration?.installing;
        if (installing?.state !== 'installing') {
            return Promise.resolve();
        }
        return new Promise((resolve) => {
            installing.addEventListener('statechange', () => {
                if (installing.state !== 'installing') {
                    resolve();
                }
            });
            this.env.setTimeout(resolve, ms);
        });
    }

    /**
     * SKIP_WAITING an die wartende Version; fertig, sobald sie diese Seite übernimmt
     * (controllerchange, setzt controllerChanged) oder nach der Frist (dann bleibt es bei der
     * laufenden Version, siehe apply).
     */
    private activate(waiting: UpdateWorker): Promise<void> {
        return new Promise((resolve) => {
            const done = (): void => {
                this.env.container.removeEventListener('controllerchange', done);
                resolve();
            };
            this.env.container.addEventListener('controllerchange', done);
            this.env.setTimeout(done, TAKEOVER_TIMEOUT_MS);
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

/** Für Seiten, die selbst prüfen (z. B. „Seite nicht gefunden“); in main.ts bereitgestellt. */
export const UPDATER_KEY: InjectionKey<AppUpdater> = Symbol('AppUpdater');

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
 * Beim Seitenwechsel auf eine andere Seite (anderer Pfad, nicht beim Entfernen eines Fragments wie
 * #token=…) die neue Version laden statt in der alten App weiterzublättern. Beim Start entscheidet
 * `startup` (AppUpdater.checkOnStart): true = die App lädt neu, die erste Seite erscheint nicht.
 */
export function installUpdateGuard(
    router: Router,
    updater: AppUpdater,
    startup: Promise<boolean> = Promise.resolve(false),
): void {
    router.beforeEach(async (to, from) => {
        if (from === START_LOCATION) {
            return !(await startup);
        }
        if (to.path === from.path) {
            return true;
        }
        const url = new URL(router.resolve(to).href, window.location.href).href;
        if (await updater.applyNow(url)) {
            // Die Seite wird gerade neu geladen: Seitenwechsel offen lassen, nicht abbrechen. Ein
            // Abbruch setzte bei Zurück/Vor (popstate) die Historie zurück (history.go), und das
            // überholte das Neuladen; die Seite landete auf der alten Adresse und Version.
            return new Promise<never>(() => undefined);
        }
        return true;
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
            void updater.applyNow(url);
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
            // Gleiche Adresse (auch mit #token=…): assign wäre nur ein Sprung zum Fragment.
            if (url === window.location.href) {
                window.location.reload();
            } else {
                window.location.assign(url);
            }
        },
        setTimeout: (callback, ms) => window.setTimeout(callback, ms),
        buildId,
        fetchBuildId: () => fetchServedBuildId(),
    };
}
