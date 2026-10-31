import { apiDownload, apiRequest, isRecord, type ApiResult, type DownloadedFile } from './client';
import { isId, isUtcTime, nullableString } from './devices';

/** Standort in der Historie (aktueller Name und Pfad, DeviceEventJson::location). */
export interface EventLocation {
    readonly id: number;
    readonly name: string;
    readonly path: string;
}

/** Eintrag von GET devices/{id}/events (DeviceEventJson::event). */
export interface DeviceEventEntry {
    readonly id: number;
    /** created, imported, state_changed, due_changed, field_changed. */
    readonly type: string;
    /** Deutsche Bezeichnung, z. B. „Umgebucht“, „Umbuchung nicht übernommen“. */
    readonly typeLabel: string;
    /** Aktionszeit (Offline-Buchung: Zeit auf dem Handy), UTC `…Z`. */
    readonly occurredAt: string;
    /** Eingang beim Server, UTC `…Z`. */
    readonly receivedAt: string;
    /** false: nicht übernommen, nur Historie (Grund in notAppliedLabel). */
    readonly applied: boolean;
    readonly notAppliedLabel: string | null;
    /** Anzeigename (anonymisiert „Gelöschter Benutzer #ID“). */
    readonly userName: string;
    readonly comment: string | null;
    readonly location: {
        readonly from: EventLocation | null;
        readonly to: EventLocation | null;
    } | null;
    readonly status: { readonly fromLabel: string | null; readonly toLabel: string | null } | null;
    /** Prüfdatum alt/neu (JJJJ-MM-TT oder null). */
    readonly inspectionDue: { readonly from: string | null; readonly to: string | null } | null;
    readonly field: {
        readonly name: string;
        readonly label: string;
        readonly old: string | null;
        readonly new: string | null;
    } | null;
}

export interface DeviceEventPage {
    readonly events: readonly DeviceEventEntry[];
    readonly total: number;
}

/** null = nicht angegeben; undefined = ungültig. */
function parseLocation(json: unknown): EventLocation | null | undefined {
    if (json === null) {
        return null;
    }
    if (
        !isRecord(json) ||
        !isId(json['id']) ||
        typeof json['name'] !== 'string' ||
        typeof json['path'] !== 'string'
    ) {
        return undefined;
    }
    return { id: json['id'], name: json['name'], path: json['path'] };
}

function parseLocationChange(json: unknown): DeviceEventEntry['location'] | undefined {
    if (json === null) {
        return null;
    }
    if (!isRecord(json)) {
        return undefined;
    }
    const from = parseLocation(json['from']);
    const to = parseLocation(json['to']);
    return from === undefined || to === undefined ? undefined : { from, to };
}

function parseStatusChange(json: unknown): DeviceEventEntry['status'] | undefined {
    if (json === null) {
        return null;
    }
    if (!isRecord(json)) {
        return undefined;
    }
    const fromLabel = nullableString(json['from_label']);
    const toLabel = nullableString(json['to_label']);
    return fromLabel === undefined || toLabel === undefined ? undefined : { fromLabel, toLabel };
}

function parseDueChange(json: unknown): DeviceEventEntry['inspectionDue'] | undefined {
    if (json === null) {
        return null;
    }
    if (!isRecord(json)) {
        return undefined;
    }
    const from = nullableString(json['from']);
    const to = nullableString(json['to']);
    return from === undefined || to === undefined ? undefined : { from, to };
}

function parseFieldChange(json: unknown): DeviceEventEntry['field'] | undefined {
    if (json === null) {
        return null;
    }
    if (!isRecord(json) || typeof json['name'] !== 'string' || typeof json['label'] !== 'string') {
        return undefined;
    }
    const old = nullableString(json['old']);
    const next = nullableString(json['new']);
    return old === undefined || next === undefined
        ? undefined
        : { name: json['name'], label: json['label'], old, new: next };
}

export function parseDeviceEvent(json: unknown): DeviceEventEntry | null {
    if (!isRecord(json) || !isRecord(json['user'])) {
        return null;
    }
    const { id, type, applied } = json;
    const typeLabel = json['type_label'];
    const occurredAt = json['occurred_at'];
    const receivedAt = json['received_at'];
    const notAppliedLabel = nullableString(json['not_applied_label']);
    const userName = json['user']['name'];
    const comment = nullableString(json['comment']);
    const location = parseLocationChange(json['location']);
    const status = parseStatusChange(json['status']);
    const inspectionDue = parseDueChange(json['inspection_due']);
    const field = parseFieldChange(json['field']);
    if (
        !isId(id) ||
        typeof type !== 'string' ||
        typeof typeLabel !== 'string' ||
        !isUtcTime(occurredAt) ||
        !isUtcTime(receivedAt) ||
        typeof applied !== 'boolean' ||
        notAppliedLabel === undefined ||
        typeof userName !== 'string' ||
        comment === undefined ||
        location === undefined ||
        status === undefined ||
        inspectionDue === undefined ||
        field === undefined
    ) {
        return null;
    }
    return {
        id,
        type,
        typeLabel,
        occurredAt,
        receivedAt,
        applied,
        notAppliedLabel,
        userName,
        comment,
        location,
        status,
        inspectionDue,
        field,
    };
}

export function parseDeviceEventPage(json: unknown): DeviceEventPage | null {
    if (!isRecord(json) || !Array.isArray(json['events'])) {
        return null;
    }
    const total = json['total'];
    if (typeof total !== 'number' || !Number.isSafeInteger(total) || total < 0) {
        return null;
    }
    const events: DeviceEventEntry[] = [];
    for (const entry of json['events']) {
        const event = parseDeviceEvent(entry);
        if (event === null) {
            return null;
        }
        events.push(event);
    }
    return { events, total };
}

/** Eintrag von GET device-events: wie oben, dazu das Gerät (aktuelle Kennung und Typ). */
export interface TenantEventEntry extends DeviceEventEntry {
    readonly device: {
        readonly id: number;
        readonly identifier: string;
        readonly deviceType: { readonly id: number; readonly name: string };
    };
}

export interface TenantEventPage {
    readonly events: readonly TenantEventEntry[];
    readonly total: number;
}

function parseTenantEvent(json: unknown): TenantEventEntry | null {
    const event = parseDeviceEvent(json);
    if (event === null || !isRecord(json) || !isRecord(json['device'])) {
        return null;
    }
    const device = json['device'];
    const type = device['device_type'];
    if (
        !isId(device['id']) ||
        typeof device['identifier'] !== 'string' ||
        !isRecord(type) ||
        !isId(type['id']) ||
        typeof type['name'] !== 'string'
    ) {
        return null;
    }
    return {
        ...event,
        device: {
            id: device['id'],
            identifier: device['identifier'],
            deviceType: { id: type['id'], name: type['name'] },
        },
    };
}

export function parseTenantEventPage(json: unknown): TenantEventPage | null {
    if (!isRecord(json) || !Array.isArray(json['events'])) {
        return null;
    }
    const total = json['total'];
    if (typeof total !== 'number' || !Number.isSafeInteger(total) || total < 0) {
        return null;
    }
    const events: TenantEventEntry[] = [];
    for (const entry of json['events']) {
        const event = parseTenantEvent(entry);
        if (event === null) {
            return null;
        }
        events.push(event);
    }
    return { events, total };
}

/** GET device-events (Admin): Verlauf der ganzen Stadt, neueste zuerst. */
export function fetchTenantEvents(
    query: { readonly limit: number; readonly offset: number },
    fetchFn?: typeof fetch,
): Promise<ApiResult<TenantEventPage>> {
    const params = new URLSearchParams({
        limit: String(query.limit),
        offset: String(query.offset),
    });
    return apiRequest(`device-events?${params.toString()}`, parseTenantEventPage, {
        ...(fetchFn && { fetchFn }),
    });
}

/** GET devices/{id}/events: neueste zuerst (nach Eingang), auch ausgesonderte Geräte. */
export function fetchDeviceEvents(
    id: number,
    query: { readonly limit: number; readonly offset: number },
    fetchFn?: typeof fetch,
): Promise<ApiResult<DeviceEventPage>> {
    const params = new URLSearchParams({
        limit: String(query.limit),
        offset: String(query.offset),
    });
    return apiRequest(`devices/${String(id)}/events?${params.toString()}`, parseDeviceEventPage, {
        ...(fetchFn && { fetchFn }),
    });
}

/** Filter des Exports der Historie (DeviceHistoryExportHandler); Tage JJJJ-MM-TT, beide einschließlich. */
export interface HistoryExportParams {
    readonly from: string;
    readonly to: string;
    readonly deviceTypeId: number | null;
    /** Immer mit Fächern (include_sublocations=1). */
    readonly locationId: number | null;
    readonly order: 'newest' | 'oldest';
}

export function historyExportSearchParams(params: HistoryExportParams): URLSearchParams {
    const search = new URLSearchParams({ from: params.from, to: params.to });
    if (params.deviceTypeId !== null) {
        search.set('device_type_id', String(params.deviceTypeId));
    }
    if (params.locationId !== null) {
        search.set('location_id', String(params.locationId));
        search.set('include_sublocations', '1');
    }
    if (params.order !== 'newest') {
        search.set('order', params.order);
    }
    return search;
}

/** GET device-events/export: Historie der Stadt als CSV (Fehler wie 409 bei zu vielen Einträgen als ApiError). */
export function downloadHistoryExport(
    params: HistoryExportParams,
    fetchFn?: typeof fetch,
): Promise<ApiResult<DownloadedFile>> {
    return apiDownload(
        `device-events/export?${historyExportSearchParams(params).toString()}`,
        'historie.csv',
        fetchFn,
    );
}
