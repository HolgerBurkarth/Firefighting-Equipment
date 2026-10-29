import type { MockRequest, MockResponse } from '../fixtures';

/** Gerät wie DeviceJson::listItem in PHP. */
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

/** Geräte mit den IDs 1 … count. */
export function manyDevices(count: number): Record<string, unknown>[] {
    return Array.from({ length: count }, (_, i) => deviceJson(i + 1));
}

/**
 * GET api/v1/devices wie DeviceListHandler: Blöcke nach `limit`/`offset`; mit `q` die Treffer aus
 * `search` (Suche auf dem Server simuliert der Test selbst).
 */
export function devicesEndpoint(
    all: readonly Record<string, unknown>[],
    search: (q: string) => readonly Record<string, unknown>[] = () => [],
    total: number = all.length,
): (request: MockRequest) => MockResponse {
    return (request) => {
        const params = new URL(request.url).searchParams;
        const limit = Number(params.get('limit') ?? '50');
        const offset = Number(params.get('offset') ?? '0');
        const q = params.get('q');
        if (q !== null) {
            const hits = search(q);
            return {
                status: 200,
                body: { devices: hits.slice(0, limit), total: hits.length, limit, offset },
            };
        }
        return {
            status: 200,
            body: { devices: all.slice(offset, offset + limit), total, limit, offset },
        };
    };
}

/** Leere Geräteliste für Tests, die „Suchen“ nur durchlaufen (die Seite lädt immer die Liste). */
export const NO_DEVICES = { 'GET api/v1/devices': devicesEndpoint([]) } as const;
