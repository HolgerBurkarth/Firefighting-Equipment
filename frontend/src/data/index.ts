import { onSessionEnded, session } from '../auth/session';
import { browserStorage } from '../runtime/theme';
import { DeviceHistory } from './deviceHistory';
import { DeviceStore } from './deviceStore';
import { LocationStore } from './locationStore';
import { setBookingNotice } from './booking';
import { addRecent, clearRecent, loadRecent } from './recentDevices';
import { recentLocations } from './recentLocations';

/**
 * Zentrale Datenschicht (KONZEPT.md, "User am Handy – Entscheidungen", 13): Geräte und Standorte
 * lesen Seiten nur hier, nie direkt über src/api/.
 */
export const deviceStore = new DeviceStore();
export const locationStore = new LocationStore();

/** Verlauf eines Geräts („Details und Verlauf“), je Ansicht neu. */
export function deviceHistory(id: number): DeviceHistory {
    return new DeviceHistory(id);
}

/** IDs „Zuletzt benutzt“ des angemeldeten Kontos, neueste zuerst (ohne Sitzung leer). */
export function recentDeviceIds(storage = browserStorage()): number[] {
    return session.value === null ? [] : loadRecent(storage, session.value);
}

/** Gerät geöffnet oder umgebucht: nach vorn in „Zuletzt benutzt“. */
export function rememberDevice(id: number, storage = browserStorage()): void {
    if (session.value !== null) {
        addRecent(storage, session.value, id);
    }
}

/** Zuletzt benutzte Ziele beim Umbuchen (höchstens 3), neueste zuerst (ohne Sitzung leer). */
export function recentLocationIds(storage = browserStorage()): number[] {
    return session.value === null ? [] : recentLocations.load(storage, session.value);
}

/** Eigenes Ziel einer Umbuchung: nach vorn unter die zuletzt benutzten Standorte. */
export function rememberLocation(id: number, storage = browserStorage()): void {
    if (session.value !== null) {
        recentLocations.add(storage, session.value, id);
    }
}

interface DataStore {
    refresh(reason: 'visible'): Promise<void>;
    clear(): void;
}

/**
 * Löscht die Daten, wenn die Sitzung endet („Zuletzt benutzt“ nur beim Abmelden), und frischt sie
 * auf, wenn die App wieder sichtbar wird (nur Daten, die schon angefordert wurden; siehe
 * Refresher). Einmal beim Start, nur unter Städten.
 */
export function installDataLayer(
    doc: Document = document,
    stores: readonly DataStore[] = [deviceStore, locationStore],
    storage = browserStorage(),
): () => void {
    const stopSession = onSessionEnded((ended, reason) => {
        for (const store of stores) {
            store.clear();
        }
        setBookingNotice(null);
        // „Zuletzt benutzt“ (Geräte, Standorte) gilt je Konto über Sitzungen hinweg; nur Abmelden
        // löscht es.
        if (reason === 'logout') {
            clearRecent(storage, ended);
            recentLocations.clear(storage, ended);
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
