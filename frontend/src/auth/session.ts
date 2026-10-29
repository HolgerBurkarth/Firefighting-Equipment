import { readonly, shallowRef } from 'vue';
import type { ApiError } from '../api/client';
import { fetchMe, logout as apiLogout, type AuthState } from '../api/auth';

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

const endedListeners = new Set<() => void>();

/**
 * Wird aufgerufen, wenn eine bekannte Sitzung endet (Abmelden, 401, Sitzung vergessen) oder ein
 * anderes Konto sie ersetzt. Die Datenschicht (src/data/) löscht dann die Daten des Kontos. Liefert
 * eine Funktion zum Abmelden des Empfängers.
 */
export function onSessionEnded(listener: () => void): () => void {
    endedListeners.add(listener);
    return () => endedListeners.delete(listener);
}

function sameAccount(a: AuthState, b: AuthState): boolean {
    return a.user.id === b.user.id && a.tenant?.slug === b.tenant?.slug;
}

/** Meldet das Ende, wenn bisher ein Konto angemeldet war und `next` ein anderes oder keins ist. */
function notifyIfEnded(next: AuthState | null): void {
    const previous = current.value;
    if (previous !== null && (next === null || !sameAccount(previous, next))) {
        for (const listener of endedListeners) {
            listener();
        }
    }
}

/** Fehler der laufenden Sitzungsprüfung, solange sie wegen einer Störung wiederholt wird. */
export const sessionCheckError = readonly(checkError);

export function setSession(state: AuthState | null): void {
    notifyIfEnded(state);
    current.value = state;
    known = true;
}

/** Vergisst die Sitzung (z. B. nach 401); der nächste Wächter fragt auth/me erneut. */
export function resetSession(): void {
    generation += 1;
    notifyIfEnded(null);
    current.value = null;
    checkError.value = null;
    known = false;
    pending = null;
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

async function check(options: EnsureSessionOptions): Promise<AuthState | null> {
    const own = generation;
    const wait = options.wait ?? waitForRetry;
    for (let attempt = 0; ; attempt += 1) {
        const result = await fetchMe(options.fetchFn);
        if (own !== generation) {
            return current.value;
        }
        if (result.ok || !isTransientError(result.error)) {
            checkError.value = null;
            setSession(result.ok ? result.data : null);
            return current.value;
        }
        checkError.value = result.error;
        await wait(RETRY_DELAYS_MS[Math.min(attempt, RETRY_DELAYS_MS.length - 1)] ?? 15000);
        if (own !== generation) {
            return current.value;
        }
    }
}

/**
 * Liefert die Sitzung; fragt auth/me höchstens einmal, bis sie bekannt ist. Bei einer Störung
 * (siehe isTransientError) wird automatisch erneut gefragt, bis eine Antwort kommt; solange steht
 * der Fehler in `sessionCheckError`. Nur eine echte Antwort führt zur Anmeldung.
 */
export function ensureSession(options: EnsureSessionOptions = {}): Promise<AuthState | null> {
    if (known) {
        return Promise.resolve(current.value);
    }
    if (pending === null) {
        const started = check(options).finally(() => {
            if (pending === started) {
                pending = null;
            }
        });
        pending = started;
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
    setSession(null);
    setLoginNotice('logged_out');
    return null;
}
