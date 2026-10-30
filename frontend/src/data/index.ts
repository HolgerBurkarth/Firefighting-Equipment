import type { ApiResult } from '../api/client';
import {
    conflictDevice,
    postDevice,
    postDeviceFields,
    postInspectionDue,
    postReinstate,
    postRetire,
    type DeviceActionBase,
    type DeviceDetail,
    type DeviceFieldChanges,
    type NewDeviceRequest,
    type ReinstateRequest,
} from '../api/devices';
import {
    createDeviceType,
    renameDeviceType as postDeviceTypeName,
    setDeviceTypeActive as postDeviceTypeActive,
    type DeviceType,
} from '../api/deviceTypes';
import { fetchInspectionOverview, type InspectionOverview } from '../api/inspections';
import {
    createLocation,
    fetchAllLocations,
    renameLocation as postLocationName,
    setLocationActive as postLocationActive,
    type NewLocation,
    type SavedLocation,
} from '../api/locations';
import { onSessionEnded, session } from '../auth/session';
import { browserStorage } from '../runtime/theme';
import { AdminDeviceList } from './adminDeviceList';
import { AdminOverviewStore } from './adminOverview';
import { DeviceHistory } from './deviceHistory';
import { DeviceStore } from './deviceStore';
import { DeviceTypeStore } from './deviceTypeStore';
import { INSPECTION_WINDOW_DAYS, SERVER_INSPECTION_LIMIT } from './inspections';
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
/** Dashboard und Zähler im Menü der Admin-Konsole. */
export const adminOverview = new AdminOverviewStore();
/** Verwaltung des Admins: Standortbaum mit gesperrten Standorten, alle Gerätetypen. */
export const adminLocationStore = new LocationStore(() => fetchAllLocations());
export const deviceTypeStore = new DeviceTypeStore();
/** Geräteliste der Admin-Konsole (Filter und Seiten vom Server, auch ausgesonderte). */
export const adminDeviceList = new AdminDeviceList();

/**
 * Nach einer Änderung an Standorten oder Gerätetypen lädt die Verwaltung neu (abgewartet), andere
 * Listen nur, wenn sie schon geladen sind (Namen und Pfade in der Geräteliste, gesperrte Standorte
 * in der Standortwahl), damit z. B. die Seite „Standorte“ nicht alle Geräte holt.
 */
async function afterMasterDataChange<T>(
    result: ApiResult<T>,
    admin: { refresh(reason: 'changed'): Promise<void> },
): Promise<ApiResult<T>> {
    if (result.ok) {
        if (locationStore.state.locations !== null) {
            void locationStore.refresh('changed');
        }
        if (deviceStore.state.mode !== null) {
            void deviceStore.refresh('changed');
        }
        if (adminOverview.state.overview !== null) {
            void adminOverview.refresh('changed');
        }
        void adminDeviceList.refresh('changed');
        // Die Seite schließt ihr Fenster erst mit dem neuen Stand (ein Fehler dabei bleibt im
        // Zustand der Liste).
        await admin.refresh('changed');
    }
    return result;
}

/** Neuer Standort oder neues Fach (Admin). */
export async function addLocation(location: NewLocation): Promise<ApiResult<SavedLocation>> {
    return afterMasterDataChange(await createLocation(location), adminLocationStore);
}

export async function renameLocation(id: number, name: string): Promise<ApiResult<SavedLocation>> {
    return afterMasterDataChange(await postLocationName(id, name), adminLocationStore);
}

/** Sperren (nur leer) bzw. entsperren. */
export async function setLocationActive(
    id: number,
    active: boolean,
): Promise<ApiResult<SavedLocation>> {
    return afterMasterDataChange(await postLocationActive(id, active), adminLocationStore);
}

export async function addDeviceType(name: string): Promise<ApiResult<DeviceType>> {
    return afterMasterDataChange(await createDeviceType(name), deviceTypeStore);
}

export async function renameDeviceType(id: number, name: string): Promise<ApiResult<DeviceType>> {
    return afterMasterDataChange(await postDeviceTypeName(id, name), deviceTypeStore);
}

/** Aktivieren bzw. deaktivieren (vorhandene Geräte behalten den Typ). */
export async function setDeviceTypeActive(
    id: number,
    active: boolean,
): Promise<ApiResult<DeviceType>> {
    return afterMasterDataChange(await postDeviceTypeActive(id, active), deviceTypeStore);
}

/**
 * Nach einer Änderung des Admins an einem Gerät (Stammdaten, Prüfdatum, Aussondern, Wieder
 * aufnehmen): Die Liste für „Suchen“ übernimmt den Stand sofort (ausgesonderte fallen heraus);
 * Listen mit Gerätezahlen, Prüffristen und Dashboard laden neu, sofern schon geladen. Bei 409
 * version_conflict übernimmt die Liste den aktuellen Stand aus der Antwort.
 */
function afterDeviceChange(result: ApiResult<DeviceDetail>): ApiResult<DeviceDetail> {
    if (!result.ok) {
        const current =
            result.error.code === 'version_conflict' ? conflictDevice(result.error.details) : null;
        if (current !== null) {
            deviceStore.upsert(current);
        }
        return result;
    }
    deviceStore.upsert(result.data);
    if (deviceStore.state.mode !== null) {
        void deviceStore.refresh('changed');
    }
    for (const store of [locationStore, adminLocationStore]) {
        if (store.state.locations !== null) {
            void store.refresh('changed');
        }
    }
    if (deviceTypeStore.state.types !== null) {
        void deviceTypeStore.refresh('changed');
    }
    if (adminOverview.state.overview !== null) {
        void adminOverview.refresh('changed');
    }
    void adminDeviceList.refresh('changed');
    return result;
}

/** Neues Gerät (Admin); die Listen laden danach neu wie bei jeder Änderung. */
export async function createDevice(request: NewDeviceRequest): Promise<ApiResult<DeviceDetail>> {
    return afterDeviceChange(await postDevice(request));
}

/** Stammdaten ändern (Admin; nur die angegebenen Felder). */
export async function saveDeviceFields(
    id: number,
    request: DeviceActionBase & { readonly changes: DeviceFieldChanges },
): Promise<ApiResult<DeviceDetail>> {
    return afterDeviceChange(await postDeviceFields(id, request));
}

/** Nächste Prüfung setzen oder entfernen (Admin). */
export async function saveInspectionDue(
    id: number,
    request: DeviceActionBase & { readonly nextInspectionDue: string | null },
): Promise<ApiResult<DeviceDetail>> {
    return afterDeviceChange(await postInspectionDue(id, request));
}

/** Aussondern (Admin). */
export async function retireDevice(
    id: number,
    request: DeviceActionBase,
): Promise<ApiResult<DeviceDetail>> {
    return afterDeviceChange(await postRetire(id, request));
}

/** Ein ausgesondertes Gerät wieder aufnehmen (Admin). */
export async function reinstateDevice(
    id: number,
    request: ReinstateRequest,
): Promise<ApiResult<DeviceDetail>> {
    return afterDeviceChange(await postReinstate(id, request));
}

/** Verlauf eines Geräts („Details und Verlauf“), je Ansicht neu. */
export function deviceHistory(id: number): DeviceHistory {
    return new DeviceHistory(id);
}

/**
 * Prüffristen vom Server (GET inspection-due), nur im Modus `server` der Geräteliste (zu viele
 * Geräte für die lokale Liste); sonst rechnet die Seite mit `inspectionOverview` lokal.
 */
export function fetchServerInspections(): Promise<ApiResult<InspectionOverview>> {
    return fetchInspectionOverview({
        days: INSPECTION_WINDOW_DAYS,
        limit: SERVER_INSPECTION_LIMIT,
    });
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
    stores: readonly DataStore[] = [
        deviceStore,
        locationStore,
        adminOverview,
        adminLocationStore,
        deviceTypeStore,
        adminDeviceList,
    ],
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
