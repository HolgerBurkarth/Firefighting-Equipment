import type { ApiResult } from '../api/client';
import { postBulkInspectionDue, postBulkState } from '../api/deviceBulk';
import {
    postImport,
    postImportPreview,
    type ImportPreview,
    type ImportRequest,
    type ImportSummary,
} from '../api/deviceImport';
import {
    conflictDevice,
    postDevice,
    postDeviceFields,
    postDeviceState,
    postInspectionDue,
    postReinstate,
    postRetire,
    type DeviceActionBase,
    type BookableStatus,
    type DeviceDetail,
    type DeviceFieldChanges,
    type DeviceListItem,
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
    createInvitationCode as postInvitationCode,
    revokeInvitationCode as postRevoke,
    type CreatedInvitationCode,
    type InvitationCode,
    type NewInvitationCode,
} from '../api/invitationCodes';
import {
    createLocation,
    fetchAllLocations,
    renameLocation as postLocationName,
    setLocationActive as postLocationActive,
    type NewLocation,
    type SavedLocation,
} from '../api/locations';
import { fetchNotice, postReopenNotice, postResolveNotice, type Notice } from '../api/notices';
import {
    approveUser as postApprove,
    changeUserDisplayName,
    changeUserRole,
    deleteUser as postDelete,
    rejectUser as postReject,
    setUserLocked as postLocked,
    type AdminUser,
    type ManagedRole,
} from '../api/users';
import { onSessionEnded, session, setSession } from '../auth/session';
import { browserStorage } from '../runtime/theme';
import { AdminDeviceList } from './adminDeviceList';
import { AdminOverviewStore } from './adminOverview';
import { DeviceHistory } from './deviceHistory';
import { DeviceStore } from './deviceStore';
import { DeviceTypeStore } from './deviceTypeStore';
import { INSPECTION_WINDOW_DAYS, SERVER_INSPECTION_LIMIT } from './inspections';
import { InvitationCodeStore } from './invitationCodeStore';
import { LocationStore } from './locationStore';
import { NoticeList } from './noticeList';
import { UserStore } from './userStore';
import { BookingQueue } from './bookingQueue';
import { browserLocalDb } from '../runtime/localDb';
import { newClientRef, setBookingNotice } from './booking';
import { runInBlocks, type BulkRun } from './deviceBulk';
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
/** Benutzerverwaltung des Admins (Konten der Stadt ohne gelöschte). */
export const userStore = new UserStore();
/** Einladungscodes der Stadt (Benutzerverwaltung, Reiter „Einladungscodes“). */
export const invitationCodeStore = new InvitationCodeStore();
/** Hinweise des Protokolls (Admin). */
export const noticeList = new NoticeList();
/**
 * Warteschlange der Offline-Buchungen (KONZEPT.md, "Offline-Modus – Entscheidungen", 52); eine
 * angekommene Buchung übernimmt die Liste wie eine Buchung online.
 */
export const bookingQueue = new BookingQueue({
    db: browserLocalDb(),
    post: (id, request) => postDeviceState(id, request),
    onSent: (response) => {
        deviceStore.upsert(response.device);
    },
});

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
    refreshDeviceLists();
    return result;
}

/** Listen mit Geräten, Gerätezahlen und Prüffristen neu laden, sofern schon geladen. */
function refreshDeviceLists(): void {
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

/**
 * Sammelaktion „Prüfdatum setzen“ (Admin): alle Geräte in Blöcken, je Gerät die bekannte Version.
 * Danach übernimmt „Suchen“ die neuen Stände, die Listen laden neu.
 */
export async function bulkSetInspectionDue(
    devices: readonly DeviceListItem[],
    nextInspectionDue: string | null,
    comment: string | undefined,
    onProgress?: (done: number, total: number) => void,
): Promise<BulkRun> {
    const entries = devices.map((device) => ({
        id: device.id,
        version: device.version,
        nextInspectionDue,
    }));
    return afterBulk(
        await runInBlocks(entries, (block) => postBulkInspectionDue(block, comment), onProgress),
    );
}

/** Sammelaktion „Umbuchen / Status ändern“ (Admin), je Gerät eine eigene `client_ref`. */
export async function bulkChangeState(
    devices: readonly DeviceListItem[],
    target: { readonly locationId?: number; readonly status?: BookableStatus },
    comment: string | undefined,
    onProgress?: (done: number, total: number) => void,
): Promise<BulkRun> {
    const entries = devices.map((device) => ({
        id: device.id,
        version: device.version,
        ...target,
        clientRef: newClientRef(),
    }));
    return afterBulk(
        await runInBlocks(entries, (block) => postBulkState(block, comment), onProgress),
    );
}

function afterBulk(run: BulkRun): BulkRun {
    for (const result of run.results) {
        if (result.device !== null) {
            deviceStore.upsert(result.device);
        }
    }
    // Auch nach einem Fehler: Ein Block kann angekommen sein, ohne dass die Antwort kam.
    if (run.error !== null || run.results.some((result) => result.outcome === 'applied')) {
        refreshDeviceLists();
    }
    return run;
}

/** CSV-Import prüfen (Admin; speichert nichts). */
export function previewDeviceImport(file: string): Promise<ApiResult<ImportPreview>> {
    return postImportPreview(file);
}

/** CSV-Import übernehmen (Admin; alles oder nichts), danach laden die Listen neu. */
export async function applyDeviceImport(request: ImportRequest): Promise<ApiResult<ImportSummary>> {
    const result = await postImport(request);
    if (result.ok) {
        refreshDeviceLists();
    }
    return result;
}

/**
 * Nach einer Änderung an einem Konto: Liste neu (abgewartet, die Seite zeigt danach den neuen
 * Stand), das Dashboard (Zähler der Freischaltungen) nur, wenn schon geladen. Ändert der Admin den
 * eigenen Anzeigenamen, übernimmt die Sitzung ihn (Begrüßung und Menü).
 */
async function afterUserChange(result: ApiResult<AdminUser>): Promise<ApiResult<AdminUser>> {
    if (!result.ok) {
        if (result.error.code === 'not_found' || result.error.code === 'rule_violation') {
            // Inzwischen gelöscht bzw. anders: den aktuellen Stand zeigen.
            void userStore.refresh('changed');
        }
        return result;
    }
    const current = session.value;
    if (current !== null && current.user.id === result.data.id) {
        setSession({ ...current, user: { ...current.user, displayName: result.data.displayName } });
    }
    if (adminOverview.state.overview !== null) {
        void adminOverview.refresh('changed');
    }
    await userStore.refresh('changed');
    return result;
}

/** Registrierung freischalten mit Anzeigename und Rolle (Mail an das Konto). */
export async function approveUser(
    id: number,
    request: { readonly displayName: string; readonly role: ManagedRole },
): Promise<ApiResult<AdminUser>> {
    return afterUserChange(await postApprove(id, request));
}

/** Registrierung ablehnen (Konto wird anonymisiert, Mail an die bisherige Adresse). */
export async function rejectUser(id: number): Promise<ApiResult<AdminUser>> {
    return afterUserChange(await postReject(id));
}

/** Sperren bzw. entsperren. */
export async function setUserLocked(id: number, locked: boolean): Promise<ApiResult<AdminUser>> {
    return afterUserChange(await postLocked(id, locked));
}

/**
 * Anzeigename und Rolle ändern, nur was sich ändert (erst der Name, dann die Rolle); ohne Änderung
 * null. Scheitert die Rolle, ist der Name schon gespeichert (die Liste zeigt ihn).
 */
export async function saveUser(
    user: AdminUser,
    changes: { readonly displayName?: string; readonly role?: ManagedRole },
): Promise<ApiResult<AdminUser> | null> {
    let result: ApiResult<AdminUser> | null = null;
    if (changes.displayName !== undefined) {
        result = await afterUserChange(await changeUserDisplayName(user.id, changes.displayName));
        if (!result.ok) {
            return result;
        }
    }
    if (changes.role !== undefined) {
        result = await afterUserChange(await changeUserRole(user.id, changes.role));
    }
    return result;
}

/** Löschen = anonymisieren. */
export async function deleteUser(id: number): Promise<ApiResult<AdminUser>> {
    return afterUserChange(await postDelete(id));
}

/**
 * Neuer Einladungscode; danach lädt die Liste neu (abgewartet). Den Klartext liefert nur diese
 * Antwort, die Seite zeigt ihn einmal.
 */
export async function createInvitationCode(
    request: NewInvitationCode,
): Promise<ApiResult<CreatedInvitationCode>> {
    const result = await postInvitationCode(request);
    if (result.ok) {
        await invitationCodeStore.refresh('changed');
    }
    return result;
}

/** Code widerrufen (wiederholbar); danach, auch bei 404, lädt die Liste neu. */
export async function revokeInvitationCode(id: number): Promise<ApiResult<InvitationCode>> {
    const result = await postRevoke(id);
    if (result.ok || result.error.code === 'not_found') {
        await invitationCodeStore.refresh('changed');
    }
    return result;
}

/** Ein Hinweis mit Verlauf (Seite `protokoll/<id>`). */
export function loadNotice(id: number): Promise<ApiResult<Notice>> {
    return fetchNotice(id);
}

/**
 * Nach dem Erledigen bzw. Wiederöffnen: Liste und Zähler (Menü, Dashboard) laden neu, sofern schon
 * geladen; ein unbekannter Hinweis (404) verschwindet so aus der Liste.
 */
function afterNoticeChange(result: ApiResult<Notice>): ApiResult<Notice> {
    if (result.ok || result.error.code === 'not_found') {
        void noticeList.refresh('changed');
        if (adminOverview.state.overview !== null) {
            void adminOverview.refresh('changed');
        }
    }
    return result;
}

/** Hinweis erledigen (wiederholbar), Notiz optional. */
export async function resolveNotice(id: number, note: string | null): Promise<ApiResult<Notice>> {
    return afterNoticeChange(await postResolveNotice(id, note));
}

/** Erledigten Hinweis wieder öffnen (wiederholbar). */
export async function reopenNotice(id: number): Promise<ApiResult<Notice>> {
    return afterNoticeChange(await postReopenNotice(id));
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
        userStore,
        invitationCodeStore,
        noticeList,
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
