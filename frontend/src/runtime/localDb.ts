/**
 * Kleiner Schlüssel-Wert-Speicher für den Offline-Modus (KONZEPT.md, "Offline-Modus –
 * Entscheidungen", 49): IndexedDB im Browser, im Speicher für Tests und Browser ohne IndexedDB.
 * Fehler des Speichers (privates Fenster, Platz voll …) haben keine Folgen: Lesen liefert dann null,
 * Schreiben gibt false zurück.
 */
export interface LocalDb {
    get(store: LocalDbStore, key: string): Promise<unknown>;
    put(store: LocalDbStore, key: string, value: unknown): Promise<boolean>;
    delete(store: LocalDbStore, key: string): Promise<boolean>;
}

/** Tabellen der Datenbank: lokale Kopie der Daten und Warteschlange der Buchungen. */
export type LocalDbStore = 'snapshots' | 'queue';

export const LOCAL_DB_NAME = 'ff-offline';
const LOCAL_DB_VERSION = 1;
const STORES: readonly LocalDbStore[] = ['snapshots', 'queue'];

/** Speicher im Arbeitsspeicher (Tests; Browser ohne IndexedDB verlieren die Daten beim Neuladen). */
export class MemoryDb implements LocalDb {
    readonly data = new Map<string, unknown>();

    get(store: LocalDbStore, key: string): Promise<unknown> {
        return Promise.resolve(structuredClone(this.data.get(`${store}/${key}`) ?? null));
    }

    put(store: LocalDbStore, key: string, value: unknown): Promise<boolean> {
        this.data.set(`${store}/${key}`, structuredClone(value));
        return Promise.resolve(true);
    }

    delete(store: LocalDbStore, key: string): Promise<boolean> {
        this.data.delete(`${store}/${key}`);
        return Promise.resolve(true);
    }
}

function requestResult<T>(request: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => {
            resolve(request.result);
        };
        request.onerror = () => {
            reject(request.error ?? new Error('IndexedDB'));
        };
    });
}

/** IndexedDB, geöffnet beim ersten Zugriff. */
export class IndexedDbStore implements LocalDb {
    private opened: Promise<IDBDatabase> | null = null;

    constructor(private readonly factory: IDBFactory) {}

    private open(): Promise<IDBDatabase> {
        this.opened ??= this.openVersion(LOCAL_DB_VERSION).catch((error: unknown) => {
            // Beim nächsten Zugriff erneut versuchen.
            this.opened = null;
            throw error;
        });
        return this.opened;
    }

    /**
     * Öffnet die Datenbank und legt fehlende Tabellen an. Fehlt eine Tabelle, obwohl die Version
     * schon besteht (z. B. hat ein anderes Skript die Datenbank ohne Tabellen angelegt, live
     * 31.10.2026), erneut mit der nächsten Version öffnen, damit sie angelegt wird.
     */
    private openVersion(version: number): Promise<IDBDatabase> {
        return new Promise<IDBDatabase>((resolve, reject) => {
            const request = this.factory.open(LOCAL_DB_NAME, version);
            request.onupgradeneeded = () => {
                for (const store of STORES) {
                    if (!request.result.objectStoreNames.contains(store)) {
                        request.result.createObjectStore(store);
                    }
                }
            };
            request.onsuccess = () => {
                const db = request.result;
                if (STORES.every((store) => db.objectStoreNames.contains(store))) {
                    resolve(db);
                    return;
                }
                const next = db.version + 1;
                db.close();
                resolve(this.openVersion(next));
            };
            request.onerror = () => {
                reject(request.error ?? new Error('IndexedDB'));
            };
            request.onblocked = () => {
                reject(new Error('IndexedDB blockiert'));
            };
        });
    }

    async get(store: LocalDbStore, key: string): Promise<unknown> {
        try {
            const db = await this.open();
            return (await requestResult(db.transaction(store).objectStore(store).get(key))) ?? null;
        } catch {
            return null;
        }
    }

    async put(store: LocalDbStore, key: string, value: unknown): Promise<boolean> {
        try {
            const db = await this.open();
            await requestResult(
                db.transaction(store, 'readwrite').objectStore(store).put(value, key),
            );
            return true;
        } catch {
            return false;
        }
    }

    async delete(store: LocalDbStore, key: string): Promise<boolean> {
        try {
            const db = await this.open();
            await requestResult(db.transaction(store, 'readwrite').objectStore(store).delete(key));
            return true;
        } catch {
            return false;
        }
    }
}

/** IndexedDB, wenn der Browser sie anbietet, sonst der Speicher im Arbeitsspeicher. */
export function browserLocalDb(): LocalDb {
    try {
        if (typeof indexedDB !== 'undefined') {
            return new IndexedDbStore(indexedDB);
        }
    } catch {
        // Zugriff verweigert (z. B. Einstellungen des Browsers).
    }
    return new MemoryDb();
}
