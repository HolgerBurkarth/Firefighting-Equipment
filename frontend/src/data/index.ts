import { onSessionEnded } from '../auth/session';
import { DeviceStore } from './deviceStore';
import { LocationStore } from './locationStore';

/**
 * Zentrale Datenschicht (KONZEPT.md, "User am Handy – Entscheidungen", 13): Geräte und Standorte
 * lesen Seiten nur hier, nie direkt über src/api/.
 */
export const deviceStore = new DeviceStore();
export const locationStore = new LocationStore();

interface DataStore {
    refresh(reason: 'visible'): Promise<void>;
    clear(): void;
}

/**
 * Löscht die Daten, wenn die Sitzung endet, und frischt sie auf, wenn die App wieder sichtbar wird
 * (nur Daten, die schon angefordert wurden; siehe Refresher). Einmal beim Start, nur unter Städten.
 */
export function installDataLayer(
    doc: Document = document,
    stores: readonly DataStore[] = [deviceStore, locationStore],
): () => void {
    const stopSession = onSessionEnded(() => {
        for (const store of stores) {
            store.clear();
        }
    });
    const onVisibility = (): void => {
        if (doc.visibilityState === 'visible') {
            for (const store of stores) {
                void store.refresh('visible');
            }
        }
    };
    doc.addEventListener('visibilitychange', onVisibility);
    return () => {
        stopSession();
        doc.removeEventListener('visibilitychange', onVisibility);
    };
}
