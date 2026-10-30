import type { MockRequest, MockResponse } from '../fixtures';

type Handlers = Record<string, (request: MockRequest) => MockResponse>;

/**
 * Routen für `test.use({ api })`, die je Anfrage an den aktuellen simulierten Server weitergeben:
 * `test.use` legt die Routen einmal fest, `test.beforeEach` erzeugt den Server je Test neu (der
 * Zustand eines Tests gilt sonst im nächsten Test desselben Workers weiter).
 */
export function delegate(current: () => Handlers): Handlers {
    const routes: Handlers = {};
    for (const key of Object.keys(current())) {
        routes[key] = (request) => {
            const handler = current()[key];
            if (handler === undefined) {
                throw new Error(`Keine Route ${key}`);
            }
            return handler(request);
        };
    }
    return routes;
}

const KIND_LABELS = { vehicle: 'Fahrzeug', room: 'Raum/Lager', external: 'Extern' } as const;
type Kind = keyof typeof KIND_LABELS;

/** Fach in einem Testbaum. */
export interface TestSub {
    readonly id: number;
    readonly name: string;
    readonly count?: number;
    readonly active?: boolean;
}

/** Standort der Ebene 1 in einem Testbaum (`own` = Geräte direkt dort). */
export interface TestLocation {
    readonly id: number;
    readonly name: string;
    readonly kind?: Kind;
    readonly active?: boolean;
    readonly own?: number;
    readonly children?: readonly TestSub[];
}

function subJson(parent: TestLocation, child: TestSub): Record<string, unknown> {
    const kind = parent.kind ?? 'vehicle';
    return {
        id: child.id,
        name: child.name,
        kind,
        kind_label: KIND_LABELS[kind],
        level: 2,
        parent_id: parent.id,
        active: child.active ?? true,
        device_count: child.count ?? 0,
        created_at: '2026-10-01T08:00:00Z',
        updated_at: '2026-10-01T08:00:00Z',
    };
}

/** Standort wie LocationJson in PHP (Ebene 1 mit allen Fächern). */
export function treeLocationJson(location: TestLocation): Record<string, unknown> {
    const kind = location.kind ?? 'vehicle';
    const children = location.children ?? [];
    const own = location.own ?? 0;
    return {
        id: location.id,
        name: location.name,
        kind,
        kind_label: KIND_LABELS[kind],
        level: 1,
        parent_id: null,
        active: location.active ?? true,
        device_count: own,
        device_count_total: children.reduce((sum, child) => sum + (child.count ?? 0), own),
        children: children.map((child) => subJson(location, child)),
        created_at: '2026-10-01T08:00:00Z',
        updated_at: '2026-10-01T08:00:00Z',
    };
}

/**
 * Simulierter Server für die Standorte (GET locations mit und ohne gesperrte, POST anlegen,
 * umbenennen, sperren, entsperren) mit veränderlichem Baum. `respond` ersetzt die Antwort einer
 * ändernden Anfrage (z. B. 409), dann bleibt der Baum unverändert.
 */
export function locationServer(
    initial: readonly TestLocation[],
    respond: (request: MockRequest) => MockResponse | null = () => null,
): {
    readonly api: Record<string, (request: MockRequest) => MockResponse>;
    readonly tree: () => readonly TestLocation[];
} {
    let tree = [...initial];
    let nextId = 900;
    const find = (id: number): { parent: TestLocation | null; entry: TestSub | TestLocation } => {
        for (const location of tree) {
            if (location.id === id) {
                return { parent: null, entry: location };
            }
            const child = location.children?.find((sub) => sub.id === id);
            if (child !== undefined) {
                return { parent: location, entry: child };
            }
        }
        throw new Error(`Standort ${String(id)} fehlt im Testbaum`);
    };
    const update = (id: number, change: Partial<TestSub>): Record<string, unknown> => {
        tree = tree.map((location) =>
            location.id === id
                ? { ...location, ...change }
                : {
                      ...location,
                      children: (location.children ?? []).map((child) =>
                          child.id === id ? { ...child, ...change } : child,
                      ),
                  },
        );
        const { parent, entry } = find(id);
        return parent === null ? treeLocationJson(entry) : subJson(parent, entry);
    };
    const body = (request: MockRequest): Record<string, unknown> =>
        request.body as Record<string, unknown>;
    const action = (request: MockRequest): MockResponse => {
        const replaced = respond(request);
        if (replaced !== null) {
            return replaced;
        }
        const match = /^api\/v1\/locations\/(\d+)\/(rename|lock|unlock)$/.exec(request.path);
        if (match === null) {
            throw new Error(`Unerwartet: ${request.path}`);
        }
        const id = Number(match[1]);
        const change =
            match[2] === 'rename'
                ? { name: String(body(request)['name']).trim() }
                : { active: match[2] === 'unlock' };
        return { status: 200, body: { location: update(id, change) } };
    };
    const api: Record<string, (request: MockRequest) => MockResponse> = {
        'GET api/v1/locations': (request) => {
            const all = new URL(request.url).searchParams.get('include_inactive') === '1';
            const visible = all
                ? tree
                : tree
                      .filter((location) => location.active ?? true)
                      .map((location) => ({
                          ...location,
                          children: (location.children ?? []).filter(
                              (child) => child.active ?? true,
                          ),
                      }));
            return { status: 200, body: { locations: visible.map(treeLocationJson) } };
        },
        'POST api/v1/locations': (request) => {
            const replaced = respond(request);
            if (replaced !== null) {
                return replaced;
            }
            const sent = body(request);
            const name = String(sent['name']).trim();
            nextId += 1;
            const parentId = sent['parent_id'];
            if (typeof parentId === 'number') {
                const parent = find(parentId).entry as TestLocation;
                const child = { id: nextId, name };
                tree = tree.map((location) =>
                    location.id === parentId
                        ? { ...location, children: [...(location.children ?? []), child] }
                        : location,
                );
                return { status: 201, body: { location: subJson(parent, child) } };
            }
            const created = { id: nextId, name, kind: sent['kind'] as Kind };
            tree = [...tree, created];
            return { status: 201, body: { location: treeLocationJson(created) } };
        },
    };
    for (const path of ['rename', 'lock', 'unlock']) {
        for (const location of initial) {
            api[`POST api/v1/locations/${String(location.id)}/${path}`] = action;
            for (const child of location.children ?? []) {
                api[`POST api/v1/locations/${String(child.id)}/${path}`] = action;
            }
        }
    }
    return { api, tree: () => tree };
}

/** Gerätetyp wie DeviceTypeJson in PHP. */
export function deviceTypeJson(
    id: number,
    name: string,
    deviceCount = 0,
    active = true,
): Record<string, unknown> {
    return {
        id,
        name,
        active,
        device_count: deviceCount,
        created_at: '2026-10-01T08:00:00Z',
        updated_at: '2026-10-01T08:00:00Z',
    };
}

/** Gerätetyp in einem Test (Name, Gerätezahl, aktiv). */
export interface TestDeviceType {
    readonly id: number;
    readonly name: string;
    readonly count?: number;
    readonly active?: boolean;
}

/**
 * Simulierter Server für die Gerätetypen (GET mit deaktivierten, POST anlegen, umbenennen,
 * deaktivieren, aktivieren). `respond` ersetzt die Antwort einer ändernden Anfrage.
 */
export function deviceTypeServer(
    initial: readonly TestDeviceType[],
    respond: (request: MockRequest) => MockResponse | null = () => null,
): Record<string, (request: MockRequest) => MockResponse> {
    let types = [...initial];
    let nextId = 900;
    const json = (type: TestDeviceType): Record<string, unknown> =>
        deviceTypeJson(type.id, type.name, type.count ?? 0, type.active ?? true);
    const action = (request: MockRequest): MockResponse => {
        const replaced = respond(request);
        if (replaced !== null) {
            return replaced;
        }
        const match = /^api\/v1\/device-types\/(\d+)\/(rename|activate|deactivate)$/.exec(
            request.path,
        );
        if (match === null) {
            throw new Error(`Unerwartet: ${request.path}`);
        }
        const id = Number(match[1]);
        const sent = request.body as Record<string, unknown>;
        types = types.map((type) =>
            type.id !== id
                ? type
                : match[2] === 'rename'
                  ? { ...type, name: String(sent['name']).trim() }
                  : { ...type, active: match[2] === 'activate' },
        );
        const changed = types.find((type) => type.id === id);
        if (changed === undefined) {
            throw new Error(`Gerätetyp ${String(id)} fehlt`);
        }
        return { status: 200, body: { device_type: json(changed) } };
    };
    const api: Record<string, (request: MockRequest) => MockResponse> = {
        'GET api/v1/device-types': () => ({
            status: 200,
            body: { device_types: types.map(json) },
        }),
        'POST api/v1/device-types': (request) => {
            const replaced = respond(request);
            if (replaced !== null) {
                return replaced;
            }
            nextId += 1;
            const created = {
                id: nextId,
                name: String((request.body as Record<string, unknown>)['name']).trim(),
            };
            types = [...types, created];
            return { status: 201, body: { device_type: json(created) } };
        },
    };
    for (const type of initial) {
        for (const path of ['rename', 'activate', 'deactivate']) {
            api[`POST api/v1/device-types/${String(type.id)}/${path}`] = action;
        }
    }
    return api;
}
