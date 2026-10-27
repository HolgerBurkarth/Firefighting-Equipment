import { readonly, shallowRef } from 'vue';
import type { ApiError } from '../api/client';
import { fetchMe, logout as apiLogout, type AuthState } from '../api/auth';

/** Hinweis für die Anmeldeseite nach Abmelden oder Ablauf der Sitzung. */
export type LoginNotice = 'logged_out' | 'session_expired';

/** Wartezeiten zwischen erneuten Versuchen von auth/me; der letzte Wert wiederholt sich. */
export const RETRY_DELAYS_MS: readonly number[] = [2000, 4000, 8000, 15000];

const current = shallowRef<AuthState | null>(null);
const notice = shallowRef<LoginNotice | null>(null);
const checkError = shallowRef<ApiError | null>(null);
/** true, sobald bekannt ist, ob eine Sitzung besteht (nach auth/me oder Anmelden/Abmelden). */
let known = false;
let pending: Promise<AuthState | null> | null = null;
/** Erhöht bei resetSession; eine ältere laufende Prüfung schreibt dann nichts mehr. */
let generation = 0;

/** Angemeldetes Konto (null = nicht angemeldet oder noch unbekannt). */
export const session = readonly(current);

/** Fehler der laufenden Sitzungsprüfung, solange sie wegen einer Störung wiederholt wird. */
export const sessionCheckError = readonly(checkError);

export function setSession(state: AuthState | null): void {
    current.value = state;
    known = true;
}

/** Vergisst die Sitzung (z. B. nach 401); der nächste Wächter fragt auth/me erneut. */
export function resetSession(): void {
    generation += 1;
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

export function setLoginNotice(value: LoginNotice | null): void {
    notice.value = value;
}

/** Hinweis einmal abholen (die Anmeldeseite zeigt ihn, danach ist er weg). */
export function takeLoginNotice(): LoginNotice | null {
    const value = notice.value;
    notice.value = null;
    return value;
}

/**
 * Abmelden beim Server; erst bei Erfolg wird die Sitzung vergessen (ohne Verbindung bliebe das
 * Cookie sonst gültig). Später (Offline-Modus) auch lokale Daten löschen, siehe KONZEPT.md.
 */
export async function logout(fetchFn?: typeof fetch): Promise<ApiError | null> {
    const result = await apiLogout(fetchFn);
    if (!result.ok) {
        return result.error;
    }
    setSession(null);
    notice.value = 'logged_out';
    return null;
}
