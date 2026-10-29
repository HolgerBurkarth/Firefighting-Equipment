import { apiRequest, isRecord, type ApiResult } from './client';
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
