import { apiRequest, isRecord, type ApiResult } from './client';
import { isId } from './devices';

/** Gerätetyp der Stadt (DeviceTypeJson). */
export interface DeviceType {
    readonly id: number;
    readonly name: string;
    /** Deaktiviert = kein Ziel für neue Geräte oder einen Typwechsel; vorhandene behalten ihn. */
    readonly active: boolean;
    /** Nicht ausgesonderte Geräte dieses Typs. */
    readonly deviceCount: number;
}

export function parseDeviceType(json: unknown): DeviceType | null {
    if (!isRecord(json)) {
        return null;
    }
    const { id, name, active } = json;
    const deviceCount = json['device_count'];
    if (
        !isId(id) ||
        typeof name !== 'string' ||
        typeof active !== 'boolean' ||
        typeof deviceCount !== 'number' ||
        !Number.isSafeInteger(deviceCount) ||
        deviceCount < 0
    ) {
        return null;
    }
    return { id, name, active, deviceCount };
}

export function parseDeviceTypeList(json: unknown): readonly DeviceType[] | null {
    if (!isRecord(json) || !Array.isArray(json['device_types'])) {
        return null;
    }
    const types: DeviceType[] = [];
    for (const entry of json['device_types']) {
        const type = parseDeviceType(entry);
        if (type === null) {
            return null;
        }
        types.push(type);
    }
    return types;
}

/** Antwort der ändernden Routen `{"device_type": …}`. */
export function parseDeviceTypeResponse(json: unknown): DeviceType | null {
    return isRecord(json) ? parseDeviceType(json['device_type']) : null;
}

/** GET device-types?include_inactive=1 (nach Name, auch deaktivierte; Verwaltung des Admins). */
export function fetchAllDeviceTypes(
    fetchFn?: typeof fetch,
): Promise<ApiResult<readonly DeviceType[]>> {
    return apiRequest('device-types?include_inactive=1', parseDeviceTypeList, {
        ...(fetchFn && { fetchFn }),
    });
}

/** POST device-types (Admin). Der Name geht unverändert, der Server entfernt Leerraum am Rand. */
export function createDeviceType(
    name: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<DeviceType>> {
    return apiRequest('device-types', parseDeviceTypeResponse, {
        method: 'POST',
        body: { name },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST device-types/{id}/rename (Admin). */
export function renameDeviceType(
    id: number,
    name: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<DeviceType>> {
    return apiRequest(`device-types/${String(id)}/rename`, parseDeviceTypeResponse, {
        method: 'POST',
        body: { name },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST device-types/{id}/activate bzw. deactivate (Admin, wiederholbar). */
export function setDeviceTypeActive(
    id: number,
    active: boolean,
    fetchFn?: typeof fetch,
): Promise<ApiResult<DeviceType>> {
    return apiRequest(
        `device-types/${String(id)}/${active ? 'activate' : 'deactivate'}`,
        parseDeviceTypeResponse,
        { method: 'POST', ...(fetchFn && { fetchFn }) },
    );
}
