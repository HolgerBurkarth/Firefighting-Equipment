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
        state_changed_at: '2026-10-01T08:00:00Z',
        ...overrides,
    };
}

/** Geräte mit den IDs 1 … count. */
export function manyDevices(count: number): Record<string, unknown>[] {
    return Array.from({ length: count }, (_, i) => deviceJson(i + 1));
}

/**
 * GET api/v1/devices wie DeviceListHandler: Blöcke nach `limit`/`offset`; mit `q` die Treffer aus
 * `search` (Suche auf dem Server simuliert der Test selbst), mit `scan_code` die Geräte aus `all`
 * und `search('')` mit genau diesem Scan-Code.
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
        const scanCode = params.get('scan_code');
        if (scanCode !== null) {
            const hits = [...all, ...search('')].filter((d) => d['scan_code'] === scanCode);
            return {
                status: 200,
                body: { devices: hits.slice(0, limit), total: hits.length, limit, offset },
            };
        }
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

/** GET api/v1/devices/{id} für die Umbuchen-Ansicht (Antwort mit allen Feldern, hier die der Liste). */
export function deviceDetail(device: Record<string, unknown>): Record<string, MockResponse> {
    return {
        [`GET api/v1/devices/${String(device['id'])}`]: {
            status: 200,
            body: { device: fullDevice(device) },
        },
    };
}

/** Gerät mit allen Feldern wie DeviceJson::device (GET devices/{id}): Eintrag der Liste plus Rest. */
export function fullDevice(
    device: Record<string, unknown>,
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        procured_on: null,
        note: null,
        created_at: '2026-01-05T09:00:00Z',
        updated_at: '2026-10-01T08:00:00Z',
        ...device,
        ...overrides,
    };
}

/** Standort wie LocationJson in PHP (Ebene 1 mit Unterstandorten); `own` = Geräte direkt dort. */
export function locationJson(
    id: number,
    name: string,
    children: readonly { id: number; name: string; count?: number }[] = [],
    kind: 'vehicle' | 'room' | 'external' = 'vehicle',
    own = 0,
): Record<string, unknown> {
    const labels = { vehicle: 'Fahrzeug', room: 'Raum/Lager', external: 'Extern' };
    return {
        id,
        name,
        kind,
        kind_label: labels[kind],
        level: 1,
        parent_id: null,
        active: true,
        device_count: own,
        device_count_total: children.reduce((sum, child) => sum + (child.count ?? 0), own),
        children: children.map((child) => ({
            id: child.id,
            name: child.name,
            kind,
            kind_label: labels[kind],
            level: 2,
            parent_id: id,
            active: true,
            device_count: child.count ?? 0,
        })),
    };
}

/**
 * Standortbaum der Tests: HLF 20 (10) mit G1 (11, 6 Geräte) und G2 (12, 3), LF 10 (20, ohne
 * Fächer, 2), Lager (30, Raum, 5).
 */
export const LOCATIONS = {
    'GET api/v1/locations': {
        status: 200,
        body: {
            locations: [
                locationJson(10, 'HLF 20', [
                    { id: 11, name: 'G1', count: 6 },
                    { id: 12, name: 'G2', count: 3 },
                ]),
                locationJson(20, 'LF 10', [], 'vehicle', 2),
                locationJson(30, 'Lager', [], 'room', 5),
            ],
        },
    },
} as const;

/**
 * Eintrag von GET devices/{id}/events wie DeviceEventJson::event (Standard: Umbuchung, übernommen).
 * Eingang (`created_at`, `received_at`) wie die Aktionszeit, sofern nicht angegeben (Serveraktion).
 */
export function eventJson(
    id: number,
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    const occurred = overrides['occurred_at'] ?? '2026-10-29T12:00:00Z';
    return {
        id,
        type: 'state_changed',
        type_label: 'Umgebucht',
        created_at: occurred,
        occurred_at: occurred,
        received_at: occurred,
        applied: true,
        not_applied_reason: null,
        not_applied_label: null,
        client_ref: null,
        user: { id: 7, name: 'Anna Muster' },
        comment: null,
        location: {
            from: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
            to: { id: 20, name: 'LF 10', path: 'LF 10' },
        },
        status: null,
        inspection_due: null,
        field: null,
        ...overrides,
    };
}

/** GET api/v1/devices/{id}/events: Blöcke nach `limit`/`offset` aus `all` (neueste zuerst). */
export function eventsEndpoint(
    all: readonly Record<string, unknown>[],
): (request: MockRequest) => MockResponse {
    return (request) => {
        const params = new URL(request.url).searchParams;
        const limit = Number(params.get('limit') ?? '50');
        const offset = Number(params.get('offset') ?? '0');
        return {
            status: 200,
            body: { events: all.slice(offset, offset + limit), total: all.length, limit, offset },
        };
    };
}
