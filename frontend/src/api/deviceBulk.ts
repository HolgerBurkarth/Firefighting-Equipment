import { apiRequest, isRecord, type ApiResult } from './client';
import {
    isCount,
    isId,
    parseDeviceDetail,
    type BookableStatus,
    type DeviceDetail,
} from './devices';

/**
 * Sammelaktionen der Geräteliste (Admin, KONZEPT.md "Admin-Konsole – Entscheidungen", 31):
 * POST devices/bulk/inspection-due und POST devices/bulk/state. Jedes Gerät wird auf dem Server
 * einzeln geprüft und gebucht; die Antwort nennt das Ergebnis je Gerät (DeviceBulkHandler).
 */

/** Höchstzahl der Geräte je Anfrage (DeviceBulkHandler::MAX_DEVICES in PHP, per Test gleich). */
export const BULK_MAX_DEVICES = 50;

/** Ergebnis eines Geräts: übernommen, unverändert oder Fehler. */
export type BulkOutcome = 'applied' | 'unchanged' | 'error';

/** Fehler eines Geräts wie die Antwort der einzelnen Route (z. B. `version_conflict`). */
export interface BulkItemError {
    readonly code: string;
    readonly message: string;
    readonly details: Readonly<Record<string, unknown>> | null;
}

export interface BulkItemResult {
    readonly id: number;
    readonly outcome: BulkOutcome;
    /** Dieselbe `client_ref` war schon angekommen, nichts wurde erneut geschrieben. */
    readonly replayed: boolean;
    /** Stand danach (bei Fehlern der aktuelle); null bei einem fremden oder unbekannten Gerät. */
    readonly device: DeviceDetail | null;
    readonly error: BulkItemError | null;
}

export interface BulkSummary {
    readonly total: number;
    readonly applied: number;
    readonly unchanged: number;
    readonly errors: number;
}

export interface BulkResponse {
    readonly results: readonly BulkItemResult[];
    readonly summary: BulkSummary;
}

const OUTCOMES: readonly BulkOutcome[] = ['applied', 'unchanged', 'error'];

function parseItemError(json: unknown): BulkItemError | null | undefined {
    if (json === null) {
        return null;
    }
    if (
        !isRecord(json) ||
        typeof json['error'] !== 'string' ||
        typeof json['message'] !== 'string'
    ) {
        return undefined;
    }
    const details = json['details'] ?? null;
    if (details !== null && !isRecord(details)) {
        return undefined;
    }
    return { code: json['error'], message: json['message'], details };
}

function parseItem(json: unknown): BulkItemResult | null {
    if (!isRecord(json)) {
        return null;
    }
    const { id, outcome, replayed } = json;
    const device = json['device'] === null ? null : parseDeviceDetail(json['device']);
    const error = parseItemError(json['error']);
    if (
        !isId(id) ||
        typeof outcome !== 'string' ||
        !(OUTCOMES as readonly string[]).includes(outcome) ||
        typeof replayed !== 'boolean' ||
        (json['device'] !== null && device === null) ||
        error === undefined ||
        (outcome === 'error') !== (error !== null) ||
        (outcome !== 'error' && device === null)
    ) {
        return null;
    }
    return { id, outcome: outcome as BulkOutcome, replayed, device, error };
}

export function parseBulkResponse(json: unknown): BulkResponse | null {
    if (!isRecord(json) || !Array.isArray(json['results']) || !isRecord(json['summary'])) {
        return null;
    }
    const results: BulkItemResult[] = [];
    for (const entry of json['results']) {
        const item = parseItem(entry);
        if (item === null) {
            return null;
        }
        results.push(item);
    }
    const { total, applied, unchanged, errors } = json['summary'];
    if (!isCount(total) || !isCount(applied) || !isCount(unchanged) || !isCount(errors)) {
        return null;
    }
    return { results, summary: { total, applied, unchanged, errors } };
}

/** Eintrag von POST devices/bulk/inspection-due: Gerät, Version und neues Datum (null = entfernen). */
export interface BulkDueEntry {
    readonly id: number;
    readonly version: number;
    /** JJJJ-MM-TT oder null. */
    readonly nextInspectionDue: string | null;
}

/** Eintrag von POST devices/bulk/state: mindestens Standort oder Status, eigene `client_ref`. */
export interface BulkStateEntry {
    readonly id: number;
    readonly version: number;
    readonly locationId?: number;
    readonly status?: BookableStatus;
    readonly clientRef: string;
}

function commentBody(comment: string | undefined): { comment?: string } {
    return comment === undefined ? {} : { comment };
}

export function postBulkInspectionDue(
    entries: readonly BulkDueEntry[],
    comment?: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<BulkResponse>> {
    return apiRequest('devices/bulk/inspection-due', parseBulkResponse, {
        method: 'POST',
        body: {
            devices: entries.map((entry) => ({
                id: entry.id,
                version: entry.version,
                next_inspection_due: entry.nextInspectionDue,
            })),
            ...commentBody(comment),
        },
        ...(fetchFn && { fetchFn }),
    });
}

export function postBulkState(
    entries: readonly BulkStateEntry[],
    comment?: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<BulkResponse>> {
    return apiRequest('devices/bulk/state', parseBulkResponse, {
        method: 'POST',
        body: {
            devices: entries.map((entry) => ({
                id: entry.id,
                version: entry.version,
                ...(entry.locationId !== undefined && { location_id: entry.locationId }),
                ...(entry.status !== undefined && { status: entry.status }),
                client_ref: entry.clientRef,
            })),
            ...commentBody(comment),
        },
        ...(fetchFn && { fetchFn }),
    });
}
