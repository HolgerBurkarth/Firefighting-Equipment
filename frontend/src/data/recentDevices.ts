import type { AuthState } from '../api/auth';
import { RecentIds } from './recentIds';

/**
 * „Zuletzt benutzt“ (KONZEPT.md, "User am Handy – Entscheidungen", 16): geöffnete oder umgebuchte
 * Geräte, nur auf diesem Gerät (localStorage), je Konto, höchstens 8, neueste zuerst. Gespeichert
 * werden nur IDs; Kennung, Standort und Status kommen immer aus der aktuellen Geräteliste.
 */
export const MAX_RECENT_DEVICES = 8;

const recent = new RecentIds('ff-recent-devices', MAX_RECENT_DEVICES);

type Storage = Pick<globalThis.Storage, 'getItem' | 'setItem' | 'removeItem'>;

/** Schlüssel je Stadt und Konto; null beim Super-Admin (er hat keine Geräte). */
export function recentKey(account: AuthState): string | null {
    return recent.key(account);
}

/** Gespeicherte IDs, neueste zuerst; leer ohne Eintrag, bei ungültigem Inhalt oder ohne Speicher. */
export function loadRecent(storage: Storage | null, account: AuthState): number[] {
    return recent.load(storage, account);
}

/** Setzt ein Gerät nach vorn (ältere fallen ab dem 9. heraus); liefert die neue Liste. */
export function addRecent(storage: Storage | null, account: AuthState, id: number): number[] {
    return recent.add(storage, account, id);
}

/** Beim Abmelden: Liste des Kontos löschen. */
export function clearRecent(storage: Storage | null, account: AuthState): void {
    recent.clear(storage, account);
}
