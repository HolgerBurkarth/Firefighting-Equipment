import type { AuthState } from '../api/auth';

type Storage = Pick<globalThis.Storage, 'getItem' | 'setItem' | 'removeItem'>;

/**
 * Liste zuletzt benutzter IDs (Geräte, Standorte) in localStorage, je Stadt und Konto, neueste
 * zuerst, höchstens `max`. Gespeichert werden nur IDs; die Angaben kommen immer aus den aktuellen
 * Daten.
 */
export class RecentIds {
    constructor(
        private readonly prefix: string,
        readonly max: number,
    ) {}

    /** Schlüssel je Stadt und Konto; null beim Super-Admin (er hat keine Geräte). */
    key(account: AuthState): string | null {
        return account.tenant === null
            ? null
            : `${this.prefix}:${account.tenant.slug}:${account.user.id}`;
    }

    /** Gespeicherte IDs, neueste zuerst; leer ohne Eintrag, bei ungültigem Inhalt oder ohne Speicher. */
    load(storage: Storage | null, account: AuthState): number[] {
        const key = this.key(account);
        if (storage === null || key === null) {
            return [];
        }
        try {
            const parsed: unknown = JSON.parse(storage.getItem(key) ?? '[]');
            return isIdList(parsed) ? [...new Set(parsed)].slice(0, this.max) : [];
        } catch {
            return [];
        }
    }

    /** Setzt eine ID nach vorn (ältere fallen hinter `max` heraus); liefert die neue Liste. */
    add(storage: Storage | null, account: AuthState, id: number): number[] {
        const key = this.key(account);
        const ids = [id, ...this.load(storage, account).filter((entry) => entry !== id)].slice(
            0,
            this.max,
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
    clear(storage: Storage | null, account: AuthState): void {
        const key = this.key(account);
        if (storage === null || key === null) {
            return;
        }
        try {
            storage.removeItem(key);
        } catch {
            // Ohne Speicher gibt es nichts zu löschen.
        }
    }
}

function isIdList(value: unknown): value is number[] {
    return (
        Array.isArray(value) &&
        value.every((id) => typeof id === 'number' && Number.isSafeInteger(id) && id > 0)
    );
}
