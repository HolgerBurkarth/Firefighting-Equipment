import { readonly, shallowRef } from 'vue';
import type { ApiError } from '../api/client';
import { fetchMe, logout as apiLogout, type AuthState } from '../api/auth';

/** Hinweis für die Anmeldeseite nach Abmelden, Ablauf der Sitzung oder Verbindungsfehler. */
export type LoginNotice = 'logged_out' | 'session_expired' | 'network';

const current = shallowRef<AuthState | null>(null);
const notice = shallowRef<LoginNotice | null>(null);
/** true, sobald bekannt ist, ob eine Sitzung besteht (nach auth/me oder Anmelden/Abmelden). */
let known = false;
let pending: Promise<AuthState | null> | null = null;

/** Angemeldetes Konto (null = nicht angemeldet oder noch unbekannt). */
export const session = readonly(current);

export function setSession(state: AuthState | null): void {
    current.value = state;
    known = true;
}

/** Vergisst die Sitzung (z. B. nach 401); der nächste Wächter fragt auth/me erneut. */
export function resetSession(): void {
    current.value = null;
    known = false;
    pending = null;
}

/**
 * Liefert die Sitzung; fragt auth/me höchstens einmal, bis sie bekannt ist. Ohne Verbindung
 * gilt man als nicht angemeldet (Hinweis „network“), damit die Anmeldeseite es sagt.
 */
export function ensureSession(fetchFn?: typeof fetch): Promise<AuthState | null> {
    if (known) {
        return Promise.resolve(current.value);
    }
    pending ??= fetchMe(fetchFn).then((result) => {
        pending = null;
        if (result.ok) {
            setSession(result.data);
            return result.data;
        }
        if (result.error.code === 'network') {
            notice.value = 'network';
            return null;
        }
        setSession(null);
        return null;
    });
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
