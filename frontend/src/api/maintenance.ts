import { apiRequest, isRecord, type ApiResult } from './client';
import { parseBulkResponse, type BulkResponse } from './deviceBulk';
import { isCount, isId, parseDeviceListItem, type DeviceListItem } from './devices';

/**
 * Seite „Wartung“ (Phase 2, P2.4): GET maintenance liefert die Stufen Sammelstelle und Werkstatt,
 * POST maintenance/to-workshop und maintenance/back buchen mehrere Ausrüstungen (Antwort wie die
 * Sammelaktionen der Liste, MaintenanceMoveHandler).
 */

export interface MaintenancePlace {
    readonly id: number;
    readonly name: string;
}

/** Eine Stufe: die Standorte mit der Aufgabe und die Ausrüstung dort, am längsten wartende zuerst. */
export interface MaintenanceStage {
    /** Anzeigename der Aufgabe in der Stadt (z. B. „Sammelstelle“). */
    readonly label: string;
    readonly locations: readonly MaintenancePlace[];
    /** Alle Ausrüstungen der Stufe; `devices` zeigt höchstens die ersten 200. */
    readonly total: number;
    readonly devices: readonly DeviceListItem[];
}

export interface MaintenanceState {
    readonly collection: MaintenanceStage;
    readonly workshop: MaintenanceStage;
    /** Ziel von „Zurück“; null = es gibt kein Standard-Lager. */
    readonly defaultStore: MaintenancePlace | null;
}

function parsePlace(json: unknown): MaintenancePlace | null {
    return isRecord(json) && isId(json['id']) && typeof json['name'] === 'string'
        ? { id: json['id'], name: json['name'] }
        : null;
}

function parseStage(json: unknown): MaintenanceStage | null {
    if (
        !isRecord(json) ||
        typeof json['label'] !== 'string' ||
        !Array.isArray(json['locations']) ||
        !Array.isArray(json['devices']) ||
        !isCount(json['total'])
    ) {
        return null;
    }
    const locations: MaintenancePlace[] = [];
    for (const entry of json['locations']) {
        const place = parsePlace(entry);
        if (place === null) {
            return null;
        }
        locations.push(place);
    }
    const devices: DeviceListItem[] = [];
    for (const entry of json['devices']) {
        const device = parseDeviceListItem(entry);
        if (device === null) {
            return null;
        }
        devices.push(device);
    }
    return { label: json['label'], locations, total: json['total'], devices };
}

export function parseMaintenance(json: unknown): MaintenanceState | null {
    if (!isRecord(json)) {
        return null;
    }
    const collection = parseStage(json['collection']);
    const workshop = parseStage(json['workshop']);
    const store = json['default_store'] === null ? null : parsePlace(json['default_store']);
    if (
        collection === null ||
        workshop === null ||
        (json['default_store'] !== null && store === null)
    ) {
        return null;
    }
    return { collection, workshop, defaultStore: store };
}

/** GET maintenance (Bereich Wartung, Ansehen). */
export function fetchMaintenance(fetchFn?: typeof fetch): Promise<ApiResult<MaintenanceState>> {
    return apiRequest('maintenance', parseMaintenance, { ...(fetchFn && { fetchFn }) });
}

/** Eintrag der Sammelaktionen: Ausrüstung und ihre Version. */
export interface MaintenanceEntry {
    readonly id: number;
    readonly version: number;
}

function move(
    path: 'to-workshop' | 'back',
    entries: readonly MaintenanceEntry[],
    comment: string | undefined,
    fetchFn: typeof fetch | undefined,
): Promise<ApiResult<BulkResponse>> {
    return apiRequest(`maintenance/${path}`, parseBulkResponse, {
        method: 'POST',
        body: {
            devices: entries.map((entry) => ({ id: entry.id, version: entry.version })),
            ...(comment !== undefined && { comment }),
        },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST maintenance/to-workshop: in die Werkstatt, Status „in Wartung“ (Bereich Wartung, Ändern). */
export function postToWorkshop(
    entries: readonly MaintenanceEntry[],
    comment?: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<BulkResponse>> {
    return move('to-workshop', entries, comment, fetchFn);
}

/** POST maintenance/back: ins Standard-Lager, Status „einsatzbereit“. */
export function postBack(
    entries: readonly MaintenanceEntry[],
    comment?: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<BulkResponse>> {
    return move('back', entries, comment, fetchFn);
}
