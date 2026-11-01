import type { AuthState } from '../api/auth';
import { RecentIds } from './recentIds';

/**
 * „Zuletzt benutzt“ (KONZEPT.md, "User am Handy – Entscheidungen", 16): geöffnete oder umgebuchte
 * Geräte, nur auf diesem Gerät (localStorage), je Konto, höchstens 8, zuletzt benutzte zuerst.
 * Gespeichert werden nur IDs; Kennung, Standort und Status kommen immer aus der aktuellen
 * Geräteliste. Angezeigt nach der letzten Änderung des Geräts (`orderByLastChange`).
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

/**
 * Reihenfolge der Anzeige (TODO-SPAETER T1): letzte Umbuchung/Statusänderung zuerst (egal von
 * wem), ohne Zeitpunkt am Ende; bei Gleichstand bleibt die Reihenfolge der eigenen Nutzung.
 */
export function orderByLastChange<T extends { readonly stateChangedAt: string | null }>(
    devices: readonly T[],
): T[] {
    const time = (device: T): number =>
        device.stateChangedAt === null
            ? Number.NEGATIVE_INFINITY
            : Date.parse(device.stateChangedAt);
    // Zwei fehlende Zeitpunkte ergeben NaN: dann Gleichstand.
    return [...devices].sort((a, b) => time(b) - time(a) || 0);
}
