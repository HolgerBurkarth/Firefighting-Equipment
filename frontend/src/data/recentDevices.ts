import type { AuthState } from '../api/auth';

/**
 * „Zuletzt benutzt“ (KONZEPT.md, "User am Handy – Entscheidungen", 16): geöffnete oder umgebuchte
 * Geräte, nur auf diesem Gerät (localStorage), je Konto, höchstens 8, neueste zuerst. Gespeichert
 * werden nur IDs; Kennung, Standort und Status kommen immer aus der aktuellen Geräteliste.
 */
export const MAX_RECENT_DEVICES = 8;

const KEY_PREFIX = 'ff-recent-devices';

type Storage = Pick<globalThis.Storage, 'getItem' | 'setItem' | 'removeItem'>;

/** Schlüssel je Stadt und Konto; null beim Super-Admin (er hat keine Geräte). */
export function recentKey(account: AuthState): string | null {
    return account.tenant === null
        ? null
        : `${KEY_PREFIX}:${account.tenant.slug}:${account.user.id}`;
}

function isIdList(value: unknown): value is number[] {
    return (
        Array.isArray(value) &&
        value.every((id) => typeof id === 'number' && Number.isSafeInteger(id) && id > 0)
    );
}

/** Gespeicherte IDs, neueste zuerst; leer ohne Eintrag, bei ungültigem Inhalt oder ohne Speicher. */
export function loadRecent(storage: Storage | null, account: AuthState): number[] {
    const key = recentKey(account);
    if (storage === null || key === null) {
        return [];
    }
    try {
        const parsed: unknown = JSON.parse(storage.getItem(key) ?? '[]');
        return isIdList(parsed) ? [...new Set(parsed)].slice(0, MAX_RECENT_DEVICES) : [];
    } catch {
        return [];
    }
}

/** Setzt ein Gerät nach vorn (ältere fallen ab dem 9. heraus); liefert die neue Liste. */
export function addRecent(storage: Storage | null, account: AuthState, id: number): number[] {
    const key = recentKey(account);
    const ids = [id, ...loadRecent(storage, account).filter((entry) => entry !== id)].slice(
        0,
        MAX_RECENT_DEVICES,
    );
    if (storage !== null && key !== null) {
        try {
            storage.setItem(key, JSON.stringify(ids));
        } catch {
            // Voller oder gesperrter Speicher: Die Liste gilt dann nur bis zum Neuladen nicht.
        }
    }
    return ids;
}

/** Beim Abmelden: Liste des Kontos löschen. */
export function clearRecent(storage: Storage | null, account: AuthState): void {
    const key = recentKey(account);
    if (storage === null || key === null) {
        return;
    }
    try {
        storage.removeItem(key);
    } catch {
        // Ohne Speicher gibt es nichts zu löschen.
    }
}
