import type { ApiResult } from '../../../src/api/client';
import {
    parseDeviceListItem,
    type DeviceListItem,
    type DevicePage,
} from '../../../src/api/devices';

/** Eintrag von GET devices wie DeviceJson::listItem in PHP. */
export function deviceJson(
    id: number,
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id,
        identifier: `M${id}`,
        scan_code: null,
        device_type: { id: 1, name: 'Atemschutzmaske' },
        location: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
        status: 'ready',
        status_label: 'einsatzbereit',
        next_inspection_due: null,
        version: 1,
        serial_number: null,
        manufacturer: null,
        model: null,
        ...overrides,
    };
}

export function device(id: number, overrides: Record<string, unknown> = {}): DeviceListItem {
    const parsed = parseDeviceListItem(deviceJson(id, overrides));
    if (parsed === null) {
        throw new Error(`Ungültiges Testgerät ${id}`);
    }
    return parsed;
}

/** Geräte mit den IDs from … to (einschließlich). */
export function devices(from: number, to: number): DeviceListItem[] {
    return Array.from({ length: Math.max(0, to - from + 1) }, (_, i) => device(from + i));
}

/** Simulierter Bestand für GET devices: Blöcke nach limit/offset, Aufrufe in `calls`. */
export function pagedSource(initial: readonly DeviceListItem[]) {
    let all = [...initial];
    const calls: { limit: number; offset: number }[] = [];
    return {
        calls,
        set: (next: readonly DeviceListItem[]): void => {
            all = [...next];
        },
        fetchPage: (query: { limit: number; offset: number }): Promise<ApiResult<DevicePage>> => {
            calls.push({ limit: query.limit, offset: query.offset });
            const page = all.slice(query.offset, query.offset + query.limit);
            return Promise.resolve({
                ok: true,
                status: 200,
                data: {
                    devices: page,
                    total: all.length,
                    limit: query.limit,
                    offset: query.offset,
                },
            });
        },
    };
}
