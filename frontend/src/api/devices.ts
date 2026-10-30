import { apiDownload, apiRequest, isRecord, type ApiResult, type DownloadedFile } from './client';

/** Status wie DeviceStatus in PHP. */
export type DeviceStatus = 'ready' | 'maintenance' | 'defective' | 'retired';

const STATUSES: readonly DeviceStatus[] = ['ready', 'maintenance', 'defective', 'retired'];

/** Kurzform eines Geräts (DeviceJson::summary, z. B. in GET inspection-due). */
export interface DeviceSummary {
    readonly id: number;
    readonly identifier: string;
    readonly scanCode: string | null;
    readonly deviceType: { readonly id: number; readonly name: string };
    /** `path` wie im Backend: „HLF 20 / G1“ bzw. der Name bei Ebene 1. */
    readonly location: { readonly id: number; readonly name: string; readonly path: string };
    readonly status: DeviceStatus;
    readonly statusLabel: string;
    /** JJJJ-MM-TT oder null. */
    readonly nextInspectionDue: string | null;
    readonly version: number;
}

/** Eintrag von GET devices (DeviceJson::listItem); Antworten mit allen Feldern enthalten ihn auch. */
export interface DeviceListItem extends DeviceSummary {
    readonly serialNumber: string | null;
    readonly manufacturer: string | null;
    readonly model: string | null;
    /** Letzte Umbuchung/Statusänderung (Aktionszeit, UTC `…Z`); null = nicht geliefert. */
    readonly stateChangedAt: string | null;
}

export interface DevicePage {
    readonly devices: readonly DeviceListItem[];
    readonly total: number;
    readonly limit: number;
    readonly offset: number;
}

/** Höchstes `limit` von GET devices (DeviceListHandler::MAX_LIMIT). */
export const DEVICE_PAGE_LIMIT = 200;

export function isId(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
}

function isCount(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}

function isStatus(value: unknown): value is DeviceStatus {
    return typeof value === 'string' && (STATUSES as readonly string[]).includes(value);
}

/** Zeitpunkte wie AuthJson::time in PHP: `JJJJ-MM-TTTHH:MM:SSZ` (UTC). */
const UTC_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

export function isUtcTime(value: unknown): value is string {
    return typeof value === 'string' && UTC_TIME.test(value) && !Number.isNaN(Date.parse(value));
}

/** String oder null; sonst undefined (ungültig). */
export function nullableString(value: unknown): string | null | undefined {
    if (value === null) {
        return null;
    }
    return typeof value === 'string' ? value : undefined;
}

export function parseDeviceSummary(json: unknown): DeviceSummary | null {
    if (!isRecord(json) || !isRecord(json['device_type']) || !isRecord(json['location'])) {
        return null;
    }
    const type = json['device_type'];
    const location = json['location'];
    const { id, identifier, status, version } = json;
    const statusLabel = json['status_label'];
    const scanCode = nullableString(json['scan_code']);
    const due = nullableString(json['next_inspection_due']);
    if (
        !isId(id) ||
        typeof identifier !== 'string' ||
        !isId(type['id']) ||
        typeof type['name'] !== 'string' ||
        !isId(location['id']) ||
        typeof location['name'] !== 'string' ||
        typeof location['path'] !== 'string' ||
        !isStatus(status) ||
        typeof statusLabel !== 'string' ||
        !isId(version) ||
        scanCode === undefined ||
        due === undefined
    ) {
        return null;
    }
    return {
        id,
        identifier,
        scanCode,
        deviceType: { id: type['id'], name: type['name'] },
        location: { id: location['id'], name: location['name'], path: location['path'] },
        status,
        statusLabel,
        nextInspectionDue: due,
        version,
    };
}

export function parseDeviceListItem(json: unknown): DeviceListItem | null {
    const summary = parseDeviceSummary(json);
    if (summary === null || !isRecord(json)) {
        return null;
    }
    const serialNumber = nullableString(json['serial_number']);
    const manufacturer = nullableString(json['manufacturer']);
    const model = nullableString(json['model']);
    // Neu in der Liste: fehlt es (ältere Antwort), gilt null.
    const stateChangedAt = json['state_changed_at'] ?? null;
    if (
        serialNumber === undefined ||
        manufacturer === undefined ||
        model === undefined ||
        (stateChangedAt !== null && !isUtcTime(stateChangedAt))
    ) {
        return null;
    }
    return { ...summary, serialNumber, manufacturer, model, stateChangedAt };
}

export function parseDevicePage(json: unknown): DevicePage | null {
    if (!isRecord(json) || !Array.isArray(json['devices'])) {
        return null;
    }
    const { total, limit, offset } = json;
    if (!isCount(total) || !isId(limit) || !isCount(offset)) {
        return null;
    }
    const devices: DeviceListItem[] = [];
    for (const entry of json['devices']) {
        const device = parseDeviceListItem(entry);
        if (device === null) {
            return null;
        }
        devices.push(device);
    }
    return { devices, total, limit, offset };
}

/** Sortierung wie DeviceSort in PHP. */
export type DeviceSort =
    'identifier' | 'device_type' | 'location' | 'status' | 'next_inspection_due';

/** Filter und Sortierung von GET devices und GET devices/export (DeviceListHandler). */
export interface DeviceListParams {
    /** Suche auf dem Server (Teiltext in Kennung, Scan-Code, Seriennummer, Hersteller, Modell). */
    readonly q?: string;
    /** Scan-Code genau (`scan_code`; Regeln wie `ScanCode`, sonst 400). */
    readonly scanCode?: string;
    /** Nur diese Status (`status`, kommagetrennt; mit `retired` auch ausgesonderte). */
    readonly status?: readonly DeviceStatus[];
    /** Ohne `status`: auch ausgesonderte (`include_retired=1`). */
    readonly includeRetired?: boolean;
    readonly deviceTypeId?: number;
    /** Standort, mit `includeSublocations` auch seine Fächer. */
    readonly locationId?: number;
    readonly includeSublocations?: boolean;
    /** Sortierung wie DeviceSort/SortDirection (Standard Kennung aufsteigend). */
    readonly sort?: DeviceSort;
    readonly dir?: 'asc' | 'desc';
}

export interface DevicePageQuery extends DeviceListParams {
    readonly limit: number;
    readonly offset: number;
}

/** Query-String von GET devices bzw. devices/export (ohne `limit`/`offset`). */
export function deviceListSearchParams(params: DeviceListParams): URLSearchParams {
    const search = new URLSearchParams();
    if (params.q !== undefined) {
        search.set('q', params.q);
    }
    if (params.scanCode !== undefined) {
        search.set('scan_code', params.scanCode);
    }
    if (params.status !== undefined) {
        search.set('status', params.status.join(','));
    }
    if (params.includeRetired === true) {
        search.set('include_retired', '1');
    }
    if (params.deviceTypeId !== undefined) {
        search.set('device_type_id', String(params.deviceTypeId));
    }
    if (params.locationId !== undefined) {
        search.set('location_id', String(params.locationId));
        if (params.includeSublocations === true) {
            search.set('include_sublocations', '1');
        }
    }
    if (params.sort !== undefined) {
        search.set('sort', params.sort);
    }
    if (params.dir !== undefined) {
        search.set('dir', params.dir);
    }
    return search;
}

/** GET devices (ohne Angabe von `status` ohne ausgesonderte Geräte, sortiert nach Kennung). */
export function fetchDevicePage(
    query: DevicePageQuery,
    fetchFn?: typeof fetch,
): Promise<ApiResult<DevicePage>> {
    const params = new URLSearchParams({
        limit: String(query.limit),
        offset: String(query.offset),
    });
    for (const [key, value] of deviceListSearchParams(query)) {
        params.set(key, value);
    }
    return apiRequest(`devices?${params.toString()}`, parseDevicePage, {
        ...(fetchFn && { fetchFn }),
    });
}

/** GET devices/export: CSV mit denselben Filtern und derselben Sortierung, ohne Seiten. */
export function downloadDeviceExport(
    params: DeviceListParams,
    fetchFn?: typeof fetch,
): Promise<ApiResult<DownloadedFile>> {
    const search = deviceListSearchParams(params).toString();
    return apiDownload(
        `devices/export${search === '' ? '' : `?${search}`}`,
        'geraete.csv',
        fetchFn,
    );
}

/** Antworten mit einem Gerät (`{"device": …}`); hier nur die Felder der Liste. */
export function parseDeviceResponse(json: unknown): DeviceListItem | null {
    return isRecord(json) ? parseDeviceListItem(json['device']) : null;
}

/** Gerät mit allen Feldern (DeviceJson::device), für „Details und Verlauf“. */
export interface DeviceDetail extends DeviceListItem {
    /** JJJJ-MM-TT oder null. */
    readonly procuredOn: string | null;
    /** Mehrzeilig (LF) oder null. */
    readonly note: string | null;
}

export function parseDeviceDetail(json: unknown): DeviceDetail | null {
    const item = parseDeviceListItem(json);
    if (item === null || !isRecord(json)) {
        return null;
    }
    const procuredOn = nullableString(json['procured_on']);
    const note = nullableString(json['note']);
    if (procuredOn === undefined || note === undefined) {
        return null;
    }
    return { ...item, procuredOn, note };
}

/** GET devices/{id} (auch ausgesonderte; fremd oder unbekannt 404), alle Felder. */
export function fetchDevice(id: number, fetchFn?: typeof fetch): Promise<ApiResult<DeviceDetail>> {
    return apiRequest(
        `devices/${String(id)}`,
        (json) => (isRecord(json) ? parseDeviceDetail(json['device']) : null),
        { ...(fetchFn && { fetchFn }) },
    );
}

/** Status, die ein User setzen darf (POST devices/{id}/state; aussondern nur der Admin). */
export type BookableStatus = Exclude<DeviceStatus, 'retired'>;

export const BOOKABLE_STATUSES: readonly BookableStatus[] = ['ready', 'maintenance', 'defective'];

/** Bezeichnungen wie DeviceStatus::label() in PHP. */
export const STATUS_LABELS: Readonly<Record<DeviceStatus, string>> = {
    ready: 'einsatzbereit',
    maintenance: 'in Wartung',
    defective: 'defekt',
    retired: 'ausgesondert',
};

/** Ergebnis wie BookingOutcome in PHP. */
export type BookingOutcome = 'applied' | 'unchanged' | 'history_only' | 'not_applied';

const OUTCOMES: readonly BookingOutcome[] = ['applied', 'unchanged', 'history_only', 'not_applied'];

function isOutcome(value: unknown): value is BookingOutcome {
    return typeof value === 'string' && (OUTCOMES as readonly string[]).includes(value);
}

/** Feld `result` von POST devices/{id}/state (DeviceBookingJson). */
export interface BookingResult {
    readonly outcome: BookingOutcome;
    readonly outcomeLabel: string;
    readonly reason: string | null;
    readonly reasonLabel: string | null;
    /** Deutscher Satz mit dem Ergebnis. */
    readonly message: string;
    readonly staleConfirmed: boolean;
    readonly clientRef: string | null;
    /** true: dieselbe Buchung war schon angekommen, nichts wurde erneut geschrieben. */
    readonly replayed: boolean;
    readonly noticeId: number | null;
}

export interface BookingResponse {
    readonly device: DeviceListItem;
    readonly result: BookingResult;
}

export function parseBookingResponse(json: unknown): BookingResponse | null {
    if (!isRecord(json) || !isRecord(json['result'])) {
        return null;
    }
    const device = parseDeviceListItem(json['device']);
    const result = json['result'];
    const { outcome, message, replayed } = result;
    const outcomeLabel = result['outcome_label'];
    const reason = nullableString(result['reason']);
    const reasonLabel = nullableString(result['reason_label']);
    const staleConfirmed = result['stale_confirmed'];
    const clientRef = nullableString(result['client_ref']);
    const noticeId = result['notice_id'];
    if (
        device === null ||
        !isOutcome(outcome) ||
        typeof outcomeLabel !== 'string' ||
        reason === undefined ||
        reasonLabel === undefined ||
        typeof message !== 'string' ||
        typeof staleConfirmed !== 'boolean' ||
        clientRef === undefined ||
        typeof replayed !== 'boolean' ||
        !(noticeId === null || isId(noticeId))
    ) {
        return null;
    }
    return {
        device,
        result: {
            outcome,
            outcomeLabel,
            reason,
            reasonLabel,
            message,
            staleConfirmed,
            clientRef,
            replayed,
            noticeId,
        },
    };
}

/** Body von POST devices/{id}/state (online, also ohne `occurred_at`). */
export interface StateChangeRequest {
    /** Version des Geräts, auf der die Änderung beruht (veraltet → 409 version_conflict). */
    readonly version: number;
    readonly locationId?: number;
    readonly status?: BookableStatus;
    readonly comment?: string;
    /** UUID v4 gegen doppelte Wirkung beim erneuten Senden. */
    readonly clientRef: string;
}

/** POST devices/{id}/state: Umbuchen und/oder Status setzen. */
export function postDeviceState(
    id: number,
    request: StateChangeRequest,
    fetchFn?: typeof fetch,
): Promise<ApiResult<BookingResponse>> {
    return apiRequest(`devices/${String(id)}/state`, parseBookingResponse, {
        method: 'POST',
        body: {
            version: request.version,
            ...(request.locationId !== undefined && { location_id: request.locationId }),
            ...(request.status !== undefined && { status: request.status }),
            ...(request.comment !== undefined && { comment: request.comment }),
            client_ref: request.clientRef,
        },
        ...(fetchFn && { fetchFn }),
    });
}

/** Aktueller Stand aus 409 version_conflict (`details.device`), sonst null. */
export function conflictDevice(
    details: Readonly<Record<string, unknown>> | null,
): DeviceListItem | null {
    return details === null ? null : parseDeviceListItem(details['device']);
}

/** Wie `conflictDevice`, mit allen Feldern (der Server sendet DeviceJson::device). */
export function conflictDeviceDetail(
    details: Readonly<Record<string, unknown>> | null,
): DeviceDetail | null {
    return details === null ? null : parseDeviceDetail(details['device']);
}

function parseDeviceDetailResponse(json: unknown): DeviceDetail | null {
    return isRecord(json) ? parseDeviceDetail(json['device']) : null;
}

/**
 * Geänderte Stammdaten (POST devices/{id}/fields, nur der Admin): nur angegebene Felder werden
 * gesendet, `null` leert ein optionales Feld. Kennung und Gerätetyp lassen sich nicht leeren.
 */
export interface DeviceFieldChanges {
    readonly identifier?: string;
    readonly scanCode?: string | null;
    readonly deviceTypeId?: number;
    readonly serialNumber?: string | null;
    readonly manufacturer?: string | null;
    readonly model?: string | null;
    /** JJJJ-MM-TT oder null. */
    readonly procuredOn?: string | null;
    readonly note?: string | null;
}

/** Feldnamen im Body wie DeviceFields in PHP. */
const FIELD_NAMES: Readonly<Record<keyof DeviceFieldChanges, string>> = {
    identifier: 'identifier',
    scanCode: 'scan_code',
    deviceTypeId: 'device_type_id',
    serialNumber: 'serial_number',
    manufacturer: 'manufacturer',
    model: 'model',
    procuredOn: 'procured_on',
    note: 'note',
};

export function fieldChangesBody(changes: DeviceFieldChanges): Record<string, unknown> {
    const body: Record<string, unknown> = {};
    for (const [key, name] of Object.entries(FIELD_NAMES)) {
        const value = changes[key as keyof DeviceFieldChanges];
        if (value !== undefined) {
            body[name] = value;
        }
    }
    return body;
}

/** Gemeinsam für die Aktionen des Admins: Version und optionaler Kommentar (eine Zeile). */
export interface DeviceActionBase {
    readonly version: number;
    readonly comment?: string;
}

function actionBody(request: DeviceActionBase): Record<string, unknown> {
    return {
        version: request.version,
        ...(request.comment !== undefined && { comment: request.comment }),
    };
}

/** POST devices/{id}/fields: Stammdaten ändern (je Feld ein Eintrag im Verlauf). */
export function postDeviceFields(
    id: number,
    request: DeviceActionBase & { readonly changes: DeviceFieldChanges },
    fetchFn?: typeof fetch,
): Promise<ApiResult<DeviceDetail>> {
    return apiRequest(`devices/${String(id)}/fields`, parseDeviceDetailResponse, {
        method: 'POST',
        body: { ...actionBody(request), ...fieldChangesBody(request.changes) },
        ...(fetchFn && { fetchFn }),
    });
}

/**
 * Body von POST devices (Admin): Kennung, Gerätetyp und Standort Pflicht, Status Standard
 * einsatzbereit; optionale Felder nur, wenn sie einen Wert haben.
 */
export interface NewDeviceRequest {
    readonly identifier: string;
    readonly deviceTypeId: number;
    readonly locationId: number;
    readonly status: BookableStatus;
    readonly scanCode?: string;
    readonly serialNumber?: string;
    readonly manufacturer?: string;
    readonly model?: string;
    /** JJJJ-MM-TT */
    readonly procuredOn?: string;
    /** JJJJ-MM-TT */
    readonly nextInspectionDue?: string;
    readonly note?: string;
    readonly comment?: string;
}

/** POST devices: neues Gerät (201, Eintrag „Angelegt“ im Verlauf). */
export function postDevice(
    request: NewDeviceRequest,
    fetchFn?: typeof fetch,
): Promise<ApiResult<DeviceDetail>> {
    return apiRequest('devices', parseDeviceDetailResponse, {
        method: 'POST',
        body: {
            ...fieldChangesBody({
                identifier: request.identifier,
                deviceTypeId: request.deviceTypeId,
                ...(request.scanCode !== undefined && { scanCode: request.scanCode }),
                ...(request.serialNumber !== undefined && { serialNumber: request.serialNumber }),
                ...(request.manufacturer !== undefined && { manufacturer: request.manufacturer }),
                ...(request.model !== undefined && { model: request.model }),
                ...(request.procuredOn !== undefined && { procuredOn: request.procuredOn }),
                ...(request.note !== undefined && { note: request.note }),
            }),
            location_id: request.locationId,
            status: request.status,
            ...(request.nextInspectionDue !== undefined && {
                next_inspection_due: request.nextInspectionDue,
            }),
            ...(request.comment !== undefined && { comment: request.comment }),
        },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST devices/{id}/inspection-due: nächste Prüfung setzen (JJJJ-MM-TT) oder entfernen (null). */
export function postInspectionDue(
    id: number,
    request: DeviceActionBase & { readonly nextInspectionDue: string | null },
    fetchFn?: typeof fetch,
): Promise<ApiResult<DeviceDetail>> {
    return apiRequest(`devices/${String(id)}/inspection-due`, parseDeviceDetailResponse, {
        method: 'POST',
        body: { ...actionBody(request), next_inspection_due: request.nextInspectionDue },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST devices/{id}/retire: aussondern (Kennung und Scan-Code werden frei). */
export function postRetire(
    id: number,
    request: DeviceActionBase,
    fetchFn?: typeof fetch,
): Promise<ApiResult<DeviceDetail>> {
    return apiRequest(`devices/${String(id)}/retire`, parseDeviceDetailResponse, {
        method: 'POST',
        body: actionBody(request),
        ...(fetchFn && { fetchFn }),
    });
}

/**
 * Body von POST devices/{id}/reinstate: Status Pflicht; Standort, Kennung und Gerätetyp nur, wenn
 * sie sich ändern (sonst gelten die bisherigen); `scanCode` angegeben (auch null = entfernen)
 * ersetzt den bisherigen.
 */
export interface ReinstateRequest extends DeviceActionBase {
    readonly status: BookableStatus;
    readonly locationId?: number;
    readonly identifier?: string;
    readonly scanCode?: string | null;
    readonly deviceTypeId?: number;
}

/** POST devices/{id}/reinstate: ein ausgesondertes Gerät wieder aufnehmen. */
export function postReinstate(
    id: number,
    request: ReinstateRequest,
    fetchFn?: typeof fetch,
): Promise<ApiResult<DeviceDetail>> {
    return apiRequest(`devices/${String(id)}/reinstate`, parseDeviceDetailResponse, {
        method: 'POST',
        body: {
            ...actionBody(request),
            status: request.status,
            ...(request.locationId !== undefined && { location_id: request.locationId }),
            ...fieldChangesBody({
                ...(request.identifier !== undefined && { identifier: request.identifier }),
                ...(request.scanCode !== undefined && { scanCode: request.scanCode }),
                ...(request.deviceTypeId !== undefined && { deviceTypeId: request.deviceTypeId }),
            }),
        },
        ...(fetchFn && { fetchFn }),
    });
}
