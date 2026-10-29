import { apiRequest, isRecord, type ApiResult } from './client';

/** Status wie DeviceStatus in PHP. */
export type DeviceStatus = 'ready' | 'maintenance' | 'defective' | 'retired';

const STATUSES: readonly DeviceStatus[] = ['ready', 'maintenance', 'defective', 'retired'];

/** Eintrag von GET devices (DeviceJson::listItem); Antworten mit allen Feldern enthalten ihn auch. */
export interface DeviceListItem {
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
    readonly serialNumber: string | null;
    readonly manufacturer: string | null;
    readonly model: string | null;
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

/** String oder null; sonst undefined (ungültig). */
function nullableString(value: unknown): string | null | undefined {
    if (value === null) {
        return null;
    }
    return typeof value === 'string' ? value : undefined;
}

export function parseDeviceListItem(json: unknown): DeviceListItem | null {
    if (!isRecord(json) || !isRecord(json['device_type']) || !isRecord(json['location'])) {
        return null;
    }
    const type = json['device_type'];
    const location = json['location'];
    const { id, identifier, status, version } = json;
    const statusLabel = json['status_label'];
    const scanCode = nullableString(json['scan_code']);
    const due = nullableString(json['next_inspection_due']);
    const serialNumber = nullableString(json['serial_number']);
    const manufacturer = nullableString(json['manufacturer']);
    const model = nullableString(json['model']);
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
        due === undefined ||
        serialNumber === undefined ||
        manufacturer === undefined ||
        model === undefined
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
        serialNumber,
        manufacturer,
        model,
    };
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

export interface DevicePageQuery {
    readonly limit: number;
    readonly offset: number;
    /** Suche auf dem Server (Teiltext in Kennung, Scan-Code, Seriennummer, Hersteller, Modell). */
    readonly q?: string;
}

/** GET devices (ohne ausgesonderte Geräte, sortiert nach Kennung). */
export function fetchDevicePage(
    query: DevicePageQuery,
    fetchFn?: typeof fetch,
): Promise<ApiResult<DevicePage>> {
    const params = new URLSearchParams({
        limit: String(query.limit),
        offset: String(query.offset),
    });
    if (query.q !== undefined) {
        params.set('q', query.q);
    }
    return apiRequest(`devices?${params.toString()}`, parseDevicePage, {
        ...(fetchFn && { fetchFn }),
    });
}

/** Antworten mit einem Gerät (`{"device": …}`); hier nur die Felder der Liste. */
export function parseDeviceResponse(json: unknown): DeviceListItem | null {
    return isRecord(json) ? parseDeviceListItem(json['device']) : null;
}

/** GET devices/{id} (auch ausgesonderte; fremd oder unbekannt 404). */
export function fetchDevice(
    id: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<DeviceListItem>> {
    return apiRequest(`devices/${String(id)}`, parseDeviceResponse, {
        ...(fetchFn && { fetchFn }),
    });
}
