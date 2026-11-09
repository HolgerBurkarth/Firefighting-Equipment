import type { ApiError, ApiResult } from '../api/client';
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
    postSwap,
    type SwapRequest,
    postQueuedSwap,
    type SwapResponse,
} from '../api/devices';
import {
    createDeviceType,
    renameDeviceType as postDeviceTypeName,
    setDeviceTypeActive as postDeviceTypeActive,
    setDeviceTypeSingleUse as postDeviceTypeSingleUse,
    type DeviceType,
} from '../api/deviceTypes';
import { fetchInspectionOverview, type InspectionOverview } from '../api/inspections';
import { postLend, postProcessReturns, type LendRequest } from '../api/loans';
import { postBack, postToWorkshop } from '../api/maintenance';
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
    postLocationTask,
    renameLocation as postLocationName,
    setLocationActive as postLocationActive,
    type CollectionAccepts,
    type LocationTaskId,
    type NewLocation,
    type SavedLocation,
} from '../api/locations';
import { fetchNotice, postReopenNotice, postResolveNotice, type Notice } from '../api/notices';
import {
    approveUser as postApprove,
    changeUserDisplayName,
    changeUserRole,
    deleteUser as postDelete,
    endUserClaudeConnections,
    rejectUser as postReject,
    setUserClaudeAccess,
    setUserLocked as postLocked,
    postBulkRole,
    BULK_ROLE_MAX_USERS,
    type AdminUser,
    type BulkRoleResult,
    type ClaudeAccess,
} from '../api/users';
import {
    currentPermissions,
    onPermissionsChanged,
    onSessionEnded,
    session,
    setSession,
} from '../auth/session';
import { browserStorage } from '../runtime/theme';
import { AdminDeviceList } from './adminDeviceList';
import { AdminOverviewStore, overviewScope } from './adminOverview';
import { AssignableRoleStore } from './assignableRoles';
import { DeviceHistory } from './deviceHistory';
import { DeviceStore } from './deviceStore';
import { DeviceTypeStore } from './deviceTypeStore';
import { INSPECTION_WINDOW_DAYS, SERVER_INSPECTION_LIMIT } from './inspections';
import { InvitationCodeStore } from './invitationCodeStore';
import { LocationStore } from './locationStore';
import { NoticeList } from './noticeList';
import { UserStore } from './userStore';
import { BookingQueue } from './bookingQueue';
import { OtherQueues } from './otherQueues';
import { AccountList } from './accounts';
import { currentAppBase } from '../runtime/appBase';
import { browserLocalDb } from '../runtime/localDb';
import { newClientRef, setBookingNotice } from './booking';
import { chunks, runInBlocks, type BulkRun } from './deviceBulk';
import { addRecent, clearRecent, loadRecent } from './recentDevices';
import { recentLocations } from './recentLocations';

/**
 * Zentrale Datenschicht (KONZEPT.md, "User am Handy – Entscheidungen", 13): Geräte und Standorte
 * lesen Seiten nur hier, nie direkt über src/api/.
 */
export const deviceStore = new DeviceStore();
export const locationStore = new LocationStore();
/** Dashboard und Zähler im Menü der Admin-Konsole. */
export const adminOverview = new AdminOverviewStore(undefined, undefined, () =>
    overviewScope(currentPermissions.value),
);
/** Verwaltung des Admins: Standortbaum mit gesperrten Standorten, alle Gerätetypen. */
export const adminLocationStore = new LocationStore(() => fetchAllLocations());
export const deviceTypeStore = new DeviceTypeStore();
/** Geräteliste der Admin-Konsole (Filter und Seiten vom Server, auch ausgesonderte). */
export const adminDeviceList = new AdminDeviceList();
/** Benutzerverwaltung des Admins (Konten der Stadt ohne gelöschte). */
export const userStore = new UserStore();
/** Rollen der Stadt zur Auswahl (Benutzerverwaltung und Einladungscodes, GET roles). */
export const assignableRoleStore = new AssignableRoleStore();
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
    postSwap: (request) => postQueuedSwap(request),
    onSent: (response) => {
        deviceStore.upsert(response.device);
        if (response.partner !== undefined) {
            deviceStore.upsert(response.partner);
        }
    },
});

/**
 * Warteschlangen anderer Konten auf diesem Gerät (Entscheidung 55); Name des Besitzers je Stadt in
 * localStorage.
 */
export const otherQueues = new OtherQueues({
    area: () => currentAppBase().area,
    db: browserLocalDb(),
    storage: browserStorage(),
});

/** Konten in anderen Städten auf diesem Gerät („Konto wechseln“, Plan Phase 1, Schritt 1.9). */
export const accountList = new AccountList({
    area: () => currentAppBase().area,
    db: browserLocalDb(),
    storage: browserStorage(),
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

/** Aufgabe eines Standorts der Ebene 1 setzen (null = entfernen). */
export async function setLocationTask(
    id: number,
    task: LocationTaskId | null,
    options: { defaultStore?: boolean; collectionAccepts?: CollectionAccepts } = {},
): Promise<ApiResult<SavedLocation>> {
    return afterMasterDataChange(await postLocationTask(id, task, options), adminLocationStore);
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

/** Als Einmalgerät markieren bzw. nicht (Phase 2, P2.3). */
export async function setDeviceTypeSingleUse(
    id: number,
    singleUse: boolean,
): Promise<ApiResult<DeviceType>> {
    return afterMasterDataChange(await postDeviceTypeSingleUse(id, singleUse), deviceTypeStore);
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

/**
 * Tausch (Phase 2, P2.8): beide Stände gleich übernehmen, die Listen laden neu. Nur mit Verbindung
 * (offline: P2.9).
 */
export async function swapEquipment(request: SwapRequest): Promise<ApiResult<SwapResponse>> {
    const result = await postSwap(request);
    if (result.ok) {
        deviceStore.upsert(result.data.out);
        deviceStore.upsert(result.data.in);
        refreshDeviceLists();
    } else if (result.error.code === 'version_conflict') {
        // Der Stand der betroffenen Ausrüstung kam mit der Antwort: Liste neu laden, die Seite zeigt ihn dann.
        void deviceStore.refresh('changed');
    }
    return result;
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

/** „Verleihen“ (Seite Verleih; Phase 2, P2.5): in Blöcken, je Ausrüstung die bekannte Version. */
export async function lendEquipment(
    devices: readonly { readonly id: number; readonly version: number }[],
    request: LendRequest,
    onProgress?: (done: number, total: number) => void,
): Promise<BulkRun> {
    const entries = devices.map((device) => ({ id: device.id, version: device.version }));
    return afterBulk(await runInBlocks(entries, (block) => postLend(block, request), onProgress));
}

/** „Rückgaben bearbeiten“ (Phase 2, P2.6): in die Sammelstelle bzw. ins Standard-Lager. */
export async function processReturns(
    kind: 'to-collection' | 'to-store',
    devices: readonly { readonly id: number; readonly version: number }[],
    onProgress?: (done: number, total: number) => void,
): Promise<BulkRun> {
    const entries = devices.map((device) => ({ id: device.id, version: device.version }));
    return afterBulk(
        await runInBlocks(entries, (block) => postProcessReturns(kind, block), onProgress),
    );
}

/** „Zur Werkstatt“ (Seite Wartung; Phase 2, P2.4): der Server bucht ins Ziel der Werkstatt. */
export async function maintenanceToWorkshop(
    devices: readonly DeviceListItem[],
    onProgress?: (done: number, total: number) => void,
): Promise<BulkRun> {
    const entries = devices.map((device) => ({ id: device.id, version: device.version }));
    return afterBulk(await runInBlocks(entries, (block) => postToWorkshop(block), onProgress));
}

/** „Zurück ins Lager“ (Seite Wartung): ins Standard-Lager, einsatzbereit. */
export async function maintenanceBack(
    devices: readonly DeviceListItem[],
    onProgress?: (done: number, total: number) => void,
): Promise<BulkRun> {
    const entries = devices.map((device) => ({ id: device.id, version: device.version }));
    return afterBulk(await runInBlocks(entries, (block) => postBack(block), onProgress));
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
    refreshRoleCounts();
    await userStore.refresh('changed');
    return result;
}

/** Zahl der Konten je Rolle (Rollenfilter, Auswahl), nur wenn schon geladen. */
function refreshRoleCounts(): void {
    if (assignableRoleStore.state.data !== null) {
        void assignableRoleStore.refresh('changed');
    }
}

/** Registrierung freischalten mit Anzeigename und Rolle der Stadt (Mail an das Konto). */
export async function approveUser(
    id: number,
    request: { readonly displayName: string; readonly roleId: number },
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
    changes: { readonly displayName?: string; readonly roleId?: number },
): Promise<ApiResult<AdminUser> | null> {
    let result: ApiResult<AdminUser> | null = null;
    if (changes.displayName !== undefined) {
        result = await afterUserChange(await changeUserDisplayName(user.id, changes.displayName));
        if (!result.ok) {
            return result;
        }
    }
    if (changes.roleId !== undefined) {
        result = await afterUserChange(await changeUserRole(user.id, changes.roleId));
    }
    return result;
}

/** Ergebnis von „Rolle zuweisen …“ für mehrere Konten. */
export interface BulkRoleRun {
    readonly results: readonly BulkRoleResult[];
    /** Fehler einer ganzen Anfrage (z. B. Netzwerk, 409 vor jeder Änderung); der Rest gilt als nicht gesendet. */
    readonly error: ApiError | null;
    readonly unsent: readonly number[];
}

/**
 * Rolle mehrerer Konten zuweisen (F6): Blöcke zu BULK_ROLE_MAX_USERS nacheinander, je Konto ein
 * Ergebnis; danach lädt die Liste neu (abgewartet), auch nach einem Fehler.
 */
export async function bulkAssignRole(
    userIds: readonly number[],
    roleId: number,
): Promise<BulkRoleRun> {
    const results: BulkRoleResult[] = [];
    let run: BulkRoleRun | null = null;
    let done = 0;
    for (const block of chunks(userIds, BULK_ROLE_MAX_USERS)) {
        const response = await postBulkRole(block, roleId);
        if (!response.ok) {
            run = { results, error: response.error, unsent: userIds.slice(done) };
            break;
        }
        results.push(...response.data);
        done += block.length;
    }
    if (adminOverview.state.overview !== null) {
        void adminOverview.refresh('changed');
    }
    refreshRoleCounts();
    await userStore.refresh('changed');
    return run ?? { results, error: null, unsent: [] };
}

/** Claude-Zugang setzen (F12: nur die Rolle Admin, nur Konten ohne Admin); „aus“ trennt alle Verbindungen. */
export async function setClaudeAccess(
    id: number,
    access: ClaudeAccess,
): Promise<ApiResult<AdminUser>> {
    return afterUserChange(await setUserClaudeAccess(id, access));
}

/** Alle Claude-Verbindungen eines Kontos trennen (der Zugang bleibt). */
export async function endClaudeConnections(id: number): Promise<ApiResult<AdminUser>> {
    return afterUserChange(await endUserClaudeConnections(id));
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
    refresh(reason: 'visible' | 'rights'): Promise<void>;
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
        assignableRoleStore,
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
    // Neue Rechte (Plan Phase 1, Schritt 1.7c): alles, was schon geladen war, neu (andere Datenfilter,
    // andere Bereiche; das Dashboard fragt nach overviewScope). Eingaben auf der Seite bleiben.
    const stopPermissions = onPermissionsChanged(() => {
        for (const store of stores) {
            void store.refresh('rights');
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
        stopPermissions();
        doc.removeEventListener('visibilitychange', onVisibility);
    };
}
