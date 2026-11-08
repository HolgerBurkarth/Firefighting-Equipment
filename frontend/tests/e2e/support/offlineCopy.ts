import type { Page } from '@playwright/test';

/** Lokale Kopie (IndexedDB ff-offline) des Kontos 7 der Stadt `test`: IDs der Geräte, sonst null. */
export async function storedDeviceIds(page: Page): Promise<number[] | null> {
    return page.evaluate(
        () =>
            new Promise<number[] | null>((resolve) => {
                const open = indexedDB.open('ff-offline');
                // Gibt es die Datenbank noch nicht, nicht anlegen (sonst fehlten der App die
                // Tabellen, siehe IndexedDbStore.openVersion).
                open.onupgradeneeded = () => {
                    open.transaction?.abort();
                };
                open.onerror = () => {
                    resolve(null);
                };
                open.onsuccess = () => {
                    const db = open.result;
                    if (!db.objectStoreNames.contains('snapshots')) {
                        db.close();
                        resolve(null);
                        return;
                    }
                    const get = db.transaction('snapshots').objectStore('snapshots').get('test:7');
                    get.onsuccess = () => {
                        const value = get.result as { devices?: { id: number }[] } | undefined;
                        db.close();
                        resolve(value?.devices?.map((device) => device.id) ?? null);
                    };
                    get.onerror = () => {
                        db.close();
                        resolve(null);
                    };
                };
            }),
    );
}
