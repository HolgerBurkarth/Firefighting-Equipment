import { readonly, shallowRef } from 'vue';
import type { ApiError } from '../api/client';
import { fetchMe, logout as apiLogout, parseAuthState, type AuthState } from '../api/auth';

/** Hinweis für die Anmeldeseite nach Abmelden, Ablauf der Sitzung, neuem Passwort oder Einladung. */
export type LoginNotice =
    'logged_out' | 'session_expired' | 'password_reset' | 'invitation_accepted';

/** Wartezeiten zwischen erneuten Versuchen von auth/me; der letzte Wert wiederholt sich. */
export const RETRY_DELAYS_MS: readonly number[] = [2000, 4000, 8000, 15000];

const current = shallowRef<AuthState | null>(null);
/** Ersatz, wenn sessionStorage fehlt oder gesperrt ist. */
let noticeFallback: LoginNotice | null = null;
const NOTICE_STORAGE_KEY = 'ff-login-notice';
const LOGIN_NOTICES: readonly LoginNotice[] = [
    'logged_out',
    'session_expired',
    'password_reset',
    'invitation_accepted',
];
const checkError = shallowRef<ApiError | null>(null);
/** true, sobald bekannt ist, ob eine Sitzung besteht (nach auth/me oder Anmelden/Abmelden). */
let known = false;
let pending: Promise<AuthState | null> | null = null;
/** Erhöht bei resetSession; eine ältere laufende Prüfung schreibt dann nichts mehr. */
let generation = 0;

/** Angemeldetes Konto (null = nicht angemeldet oder noch unbekannt). */
export const session = readonly(current);

/** Speicher für die letzte bestätigte Sitzung (localStorage), nur unter Städten. */
export interface StoredSessionStorage {
    getItem(key: string): string | null;
    setItem(key: string, value: string): void;
    removeItem(key: string): void;
}

let storedKey: string | null = null;
let storedStorage: StoredSessionStorage | null = null;
const usingStored = shallowRef(false);
let storedRejectedHandler: (() => void) | null = null;

/**
 * true, solange die App mit der gespeicherten Sitzung arbeitet, weil der Server (noch) nicht
 * antwortet (KONZEPT.md, "Offline-Modus – Entscheidungen", 50).
 */
export const usingStoredSession = readonly(usingStored);

/**
 * Die letzte vom Server bestätigte Sitzung unter `key` merken (main.ts, nur unter Städten; nie das
 * Cookie, nur Konto, Rolle und Stadt). Ohne Aufruf merkt sich die App keine Sitzung.
 */
export function configureStoredSession(
    key: string | null,
    storage: StoredSessionStorage | null = null,
): void {
    storedKey = key;
    storedStorage = storage;
}

/** Wird aufgerufen, wenn der Server eine gespeicherte Sitzung mit einer echten Antwort ablehnt. */
export function onStoredSessionRejected(handler: (() => void) | null): void {
    storedRejectedHandler = handler;
}

/** Gespeichert im Format von auth/me, damit beim Lesen dieselbe Prüfung gilt. */
function storedJson(state: AuthState): Record<string, unknown> {
    return {
        user: {
            id: state.user.id,
            email: state.user.email,
            name: state.user.name,
            display_name: state.user.displayName,
            role: state.user.role,
        },
        tenant: state.tenant,
    };
}

function saveStored(state: AuthState | null): void {
    if (storedKey === null || storedStorage === null) {
        return;
    }
    try {
        if (state === null) {
            storedStorage.removeItem(storedKey);
        } else {
            storedStorage.setItem(storedKey, JSON.stringify(storedJson(state)));
        }
    } catch {
        // Ohne Speicher gibt es offline keine Sitzung (wie bisher).
    }
}

function loadStored(): AuthState | null {
    if (storedKey === null || storedStorage === null) {
        return null;
    }
    try {
        const text = storedStorage.getItem(storedKey);
        return text === null ? null : parseAuthState(JSON.parse(text));
    } catch {
        return null;
    }
}

/**
 * Warum eine Sitzung endet: `logout` (Abmelden beim Server erfolgreich), `ended` (vergessen, z. B.
 * nach 401 oder einem neuen Passwort), `replaced` (ein anderes Konto meldet sich an).
 */
export type SessionEndReason = 'logout' | 'ended' | 'replaced';

type SessionEndedListener = (ended: AuthState, reason: SessionEndReason) => void;

const endedListeners = new Set<SessionEndedListener>();

/**
 * Wird aufgerufen, wenn eine bekannte Sitzung endet (Abmelden, 401, Sitzung vergessen) oder ein
 * anderes Konto sie ersetzt, mit dem bisherigen Konto. Die Datenschicht (src/data/) löscht dann die
 * Daten des Kontos. Liefert eine Funktion zum Abmelden des Empfängers.
 */
export function onSessionEnded(listener: SessionEndedListener): () => void {
    endedListeners.add(listener);
    return () => endedListeners.delete(listener);
}

function sameAccount(a: AuthState, b: AuthState): boolean {
    return a.user.id === b.user.id && a.tenant?.slug === b.tenant?.slug;
}

/** Meldet das Ende, wenn bisher ein Konto angemeldet war und `next` ein anderes oder keins ist. */
function notifyIfEnded(next: AuthState | null, reason: 'logout' | 'ended'): void {
    const previous = current.value;
    if (previous !== null && (next === null || !sameAccount(previous, next))) {
        for (const listener of endedListeners) {
            listener(previous, next === null ? reason : 'replaced');
        }
    }
}

/** Fehler der laufenden Sitzungsprüfung, solange sie wegen einer Störung wiederholt wird. */
export const sessionCheckError = readonly(checkError);

export function setSession(state: AuthState | null): void {
    applySession(state, 'ended');
}

function applySession(state: AuthState | null, reason: 'logout' | 'ended'): void {
    // Eine laufende Prüfung im Hintergrund (gespeicherte Sitzung) ist damit erledigt.
    if (usingStored.value) {
        generation += 1;
        pending = null;
        checkError.value = null;
        usingStored.value = false;
    }
    notifyIfEnded(state, reason);
    current.value = state;
    known = true;
    saveStored(state);
}

/** Vergisst die Sitzung (z. B. nach 401); der nächste Wächter fragt auth/me erneut. */
export function resetSession(): void {
    generation += 1;
    notifyIfEnded(null, 'ended');
    current.value = null;
    checkError.value = null;
    known = false;
    pending = null;
    usingStored.value = false;
    saveStored(null);
}

/**
 * Störung statt Antwort: keine Verbindung, unerwartete Antwort oder Serverfehler (5xx). Dann ist
 * offen, ob eine Sitzung besteht. Nur eine echte Antwort mit 4xx (401, oder 403/404 für eine
 * gesperrte bzw. unbekannte Stadt) ist endgültig.
 */
export function isTransientError(error: ApiError): boolean {
    return error.status === null || error.status >= 500 || error.code === 'invalid_response';
}

/** Wartet `ms` oder bis der Browser wieder online ist. */
export function waitForRetry(ms: number): Promise<void> {
    return new Promise((resolve) => {
        const done = (): void => {
            clearTimeout(timer);
            window.removeEventListener('online', done);
            resolve();
        };
        const timer = setTimeout(done, ms);
        window.addEventListener('online', done);
    });
}

export interface EnsureSessionOptions {
    readonly fetchFn?: typeof fetch;
    readonly wait?: (ms: number) => Promise<void>;
}

async function check(
    options: EnsureSessionOptions,
    early: (state: AuthState | null) => void,
): Promise<AuthState | null> {
    const own = generation;
    const wait = options.wait ?? waitForRetry;
    for (let attempt = 0; ; attempt += 1) {
        const result = await fetchMe(options.fetchFn);
        if (own !== generation) {
            return current.value;
        }
        if (result.ok || !isTransientError(result.error)) {
            const rejected = usingStored.value && !result.ok;
            checkError.value = null;
            usingStored.value = false;
            setSession(result.ok ? result.data : null);
            if (rejected) {
                storedRejectedHandler?.();
            }
            return current.value;
        }
        checkError.value = result.error;
        // Offline: mit der gespeicherten Sitzung weiterarbeiten, im Hintergrund weiter fragen.
        if (!usingStored.value && current.value === null) {
            const stored = loadStored();
            if (stored !== null) {
                current.value = stored;
                known = true;
                usingStored.value = true;
                early(stored);
            }
        }
        await wait(RETRY_DELAYS_MS[Math.min(attempt, RETRY_DELAYS_MS.length - 1)] ?? 15000);
        if (own !== generation) {
            return current.value;
        }
    }
}

/**
 * Liefert die Sitzung; fragt auth/me höchstens einmal, bis sie bekannt ist. Bei einer Störung
 * (siehe isTransientError) wird automatisch erneut gefragt, bis eine Antwort kommt; solange steht
 * der Fehler in `sessionCheckError`. Nur eine echte Antwort führt zur Anmeldung. Gibt es eine
 * gespeicherte Sitzung (configureStoredSession), gilt sie bei einer Störung sofort; die Prüfung läuft
 * im Hintergrund weiter (`usingStoredSession`).
 */
export function ensureSession(options: EnsureSessionOptions = {}): Promise<AuthState | null> {
    if (known) {
        return Promise.resolve(current.value);
    }
    if (pending === null) {
        let resolveFirst: (state: AuthState | null) => void = () => undefined;
        const first = new Promise<AuthState | null>((resolve) => {
            resolveFirst = resolve;
        });
        void check(options, resolveFirst)
            .then(resolveFirst)
            .finally(() => {
                if (pending === first) {
                    pending = null;
                }
            });
        pending = first;
    }
    return pending;
}

/**
 * Hinweis für die Anmeldeseite. Er steht in sessionStorage, damit er ein Neuladen übersteht (beim
 * Seitenwechsel lädt die App eine neue Version, siehe runtime/appUpdate.ts).
 */
export function setLoginNotice(value: LoginNotice | null): void {
    noticeFallback = value;
    try {
        if (value === null) {
            window.sessionStorage.removeItem(NOTICE_STORAGE_KEY);
        } else {
            window.sessionStorage.setItem(NOTICE_STORAGE_KEY, value);
        }
    } catch {
        // Ohne Speicher gilt der Hinweis nur bis zum nächsten Neuladen.
    }
}

/** Hinweis einmal abholen (die Anmeldeseite zeigt ihn, danach ist er weg). */
export function takeLoginNotice(): LoginNotice | null {
    let value = noticeFallback;
    try {
        const stored = window.sessionStorage.getItem(NOTICE_STORAGE_KEY);
        value = LOGIN_NOTICES.find((notice) => notice === stored) ?? null;
    } catch {
        // Ersatzwert verwenden.
    }
    setLoginNotice(null);
    return value;
}

/**
 * Abmelden beim Server; erst bei Erfolg wird die Sitzung vergessen (ohne Verbindung bliebe das
 * Cookie sonst gültig). Dabei löscht die Datenschicht die Daten des Kontos (onSessionEnded).
 */
export async function logout(fetchFn?: typeof fetch): Promise<ApiError | null> {
    const result = await apiLogout(fetchFn);
    if (!result.ok) {
        return result.error;
    }
    applySession(null, 'logout');
    setLoginNotice('logged_out');
    return null;
}
