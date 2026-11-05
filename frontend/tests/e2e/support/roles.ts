import { readFileSync } from 'node:fs';
import type { MockRequest, MockResponse } from '../fixtures';

type Handler = (request: MockRequest) => MockResponse;

/** GET permission-catalog: gemeinsame Datei mit PHP (PermissionCatalogFixtureTest). */
export const PERMISSION_CATALOG: Record<string, unknown> = JSON.parse(
    readFileSync(
        new URL('../../../../tests/Fixtures/permission-catalog.json', import.meta.url),
        'utf8',
    ),
) as Record<string, unknown>;

const ALL_AREAS = {
    dashboard: 'read',
    search: 'read',
    devices: 'write',
    locations: 'write',
    device_types: 'write',
    due: 'write',
    log: 'write',
    users: 'write',
    settings: 'write',
};
const ALL_ACTIONS = [
    'book.move',
    'book.status',
    'device.create',
    'device.edit',
    'device.retire',
    'device.code',
    'devices.bulk',
    'csv.import',
    'mail.log',
];

/** Gespeicherte Rolle der Attrappe (Felder wie RoleJson::role ohne Zeiten und Ableitungen). */
export interface MockRole {
    id: number;
    name: string;
    description: string | null;
    system_key: 'admin' | 'user' | null;
    areas: Record<string, string>;
    actions: string[];
    locations: number[] | null;
    device_types: number[] | null;
    version: number;
    accounts: number;
    invitation_codes: number;
}

/** Rolle wie RoleJson::role. */
export function roleJson(role: MockRole): Record<string, unknown> {
    const admin = role.system_key === 'admin';
    return {
        id: role.id,
        name: role.name,
        description: role.description,
        system_key: role.system_key,
        all_rights: admin,
        editable: !admin,
        deletable: role.system_key === null,
        areas: admin ? ALL_AREAS : role.areas,
        actions: admin ? ALL_ACTIONS : role.actions,
        locations: role.locations,
        device_types: role.device_types,
        version: role.version,
        created_at: '2026-11-05T08:00:00Z',
        updated_at: '2026-11-05T08:00:00Z',
        usage: { accounts: role.accounts, invitation_codes: role.invitation_codes },
    };
}

function mockRole(id: number, name: string, overrides: Partial<MockRole> = {}): MockRole {
    return {
        id,
        name,
        description: null,
        system_key: null,
        areas: {},
        actions: [],
        locations: null,
        device_types: null,
        version: 1,
        accounts: 0,
        invitation_codes: 0,
        ...overrides,
    };
}

/** Standorte und Typen der Test-Stadt für „Sieht nur …“ (role-filter-options). */
export const FILTER_OPTIONS = {
    locations: [
        {
            id: 10,
            name: 'HLF 20',
            kind: 'vehicle',
            kind_label: 'Fahrzeug',
            active: true,
            children: [
                { id: 11, name: 'G1', kind: 'vehicle', kind_label: 'Fahrzeug', active: true },
            ],
        },
        {
            id: 12,
            name: 'Lager',
            kind: 'room',
            kind_label: 'Raum/Lager',
            active: false,
            children: [],
        },
    ],
    device_types: [
        { id: 20, name: 'Atemschutzmaske', active: true },
        { id: 21, name: 'Funkgerät', active: false },
    ],
};

export interface RoleServer {
    readonly handlers: Record<string, Handler>;
    /** Rollen der Test-Stadt (ID 2) nach ID. */
    readonly roles: Map<number, MockRole>;
    /** Simuliert eine Änderung durch jemand anderen (neue Version). */
    bump(id: number, change?: Partial<MockRole>): void;
}

/**
 * Zustandsbehaftete Attrappe der Rollen des Super-Admins für die Test-Stadt (ID 2): Admin (1),
 * Benutzer (2), Gerätewart (3, mit Personen) und „Live-Test leer“ (4, ohne Nutzung, mit
 * Standortfilter); Immendingen (ID 1) hat „Geräteträger“ (50) zum Übernehmen. Neue Rollen ab ID 100
 * (die Routen stehen vorher fest, siehe `delegate`). Regeln wie RoleRepository: Admin nicht änderbar,
 * Version, Löschen nur ohne Nutzung, Name eindeutig ohne Groß-/Kleinschreibung.
 */
export function roleServer(): RoleServer {
    const roles = new Map<number, MockRole>(
        [
            mockRole(1, 'Admin', {
                system_key: 'admin',
                description: 'Alle Rechte, nicht änderbar',
                accounts: 2,
            }),
            mockRole(2, 'Benutzer', {
                system_key: 'user',
                description: 'Wie bisher; bekommt jede neue Stadt',
                areas: { search: 'read', due: 'read' },
                actions: ['book.move', 'book.status'],
                accounts: 11,
                invitation_codes: 1,
            }),
            mockRole(3, 'Gerätewart', {
                description: 'Wie Admin, ohne Benutzer und Einstellungen',
                areas: { dashboard: 'read', search: 'read', devices: 'write' },
                actions: ['book.move', 'device.edit'],
                accounts: 2,
            }),
            mockRole(4, 'Live-Test leer', { areas: { search: 'read' }, locations: [11] }),
        ].map((role) => [role.id, role]),
    );
    const otherRoles = [
        mockRole(50, 'Geräteträger', {
            description: 'Handy: suchen und buchen',
            areas: { search: 'read' },
            actions: ['book.move'],
            locations: [900],
        }),
    ];
    let nextId = 100;
    const base = 'api/v1/tenants/2/roles';
    const list = (): Record<string, unknown>[] => {
        const order = (role: MockRole): string =>
            `${role.system_key === 'admin' ? '0' : role.system_key === 'user' ? '1' : '2'}${role.name}`;
        return [...roles.values()].sort((a, b) => order(a).localeCompare(order(b))).map(roleJson);
    };
    const taken = (name: string, except?: number): boolean =>
        [...roles.values()].some(
            (role) => role.id !== except && role.name.toLowerCase() === name.toLowerCase(),
        );
    const nameTaken = (name: string): MockResponse => ({
        status: 409,
        body: {
            error: 'name_taken',
            message: `In dieser Stadt gibt es bereits eine Rolle „${name}“.`,
        },
    });
    const freeName = (base: string): string => {
        let candidate = base;
        for (let n = 2; taken(candidate); n += 1) {
            candidate = `${base} (${String(n)})`;
        }
        return candidate;
    };
    const fromBody = (body: Record<string, unknown>): Partial<MockRole> => ({
        name: String(body['name']).trim(),
        description: (body['description'] as string | null) ?? null,
        areas: body['areas'] as Record<string, string>,
        actions: body['actions'] as string[],
        locations: body['locations'] as number[] | null,
        device_types: body['device_types'] as number[] | null,
    });
    const create = (fields: Partial<MockRole> & { name: string }): MockResponse => {
        if (taken(fields.name)) {
            return nameTaken(fields.name);
        }
        const role = mockRole(nextId, fields.name, fields);
        role.id = nextId;
        nextId += 1;
        roles.set(role.id, role);
        return { status: 201, body: { role: roleJson(role) } };
    };
    const notFound: MockResponse = {
        status: 404,
        body: { error: 'not_found', message: 'Nicht gefunden.' },
    };

    const handlers: Record<string, Handler> = {
        'GET api/v1/permission-catalog': () => ({ status: 200, body: PERMISSION_CATALOG }),
        [`GET ${base}`]: () => ({ status: 200, body: { roles: list() } }),
        'GET api/v1/tenants/1/roles': () => ({
            status: 200,
            body: { roles: otherRoles.map(roleJson) },
        }),
        'GET api/v1/tenants/2/role-filter-options': () => ({ status: 200, body: FILTER_OPTIONS }),
        [`POST ${base}`]: (request) => {
            const body = request.body as Record<string, unknown>;
            if ('copy_of' in body) {
                const source = roles.get(body['copy_of'] as number);
                if (source === undefined) {
                    return {
                        status: 400,
                        body: { error: 'invalid_request', message: 'Unbekannt.' },
                    };
                }
                const admin = source.system_key === 'admin';
                return create({
                    ...source,
                    name: freeName(`${source.name} (Kopie)`),
                    system_key: null,
                    description: admin ? null : source.description,
                    areas: admin ? { ...ALL_AREAS } : { ...source.areas },
                    actions: admin ? [...ALL_ACTIONS] : [...source.actions],
                    accounts: 0,
                    invitation_codes: 0,
                    version: 1,
                });
            }
            const fields = fromBody(body);
            return create({ ...fields, name: fields.name ?? '' });
        },
        [`POST ${base}/import`]: (request) => {
            const body = request.body as Record<string, unknown>;
            const source = otherRoles.find((role) => role.id === body['source_role_id']);
            if (source === undefined) {
                return { status: 400, body: { error: 'invalid_request', message: 'Unbekannt.' } };
            }
            const name = typeof body['name'] === 'string' ? body['name'] : freeName(source.name);
            return create({
                ...source,
                name,
                locations: null,
                device_types: null,
                accounts: 0,
                invitation_codes: 0,
                version: 1,
            });
        },
    };
    for (const id of [1, 2, 3, 4, 100, 101, 102]) {
        handlers[`GET ${base}/${String(id)}`] = () => {
            const role = roles.get(id);
            return role === undefined ? notFound : { status: 200, body: { role: roleJson(role) } };
        };
        handlers[`POST ${base}/${String(id)}`] = (request) => {
            const role = roles.get(id);
            if (role === undefined) {
                return notFound;
            }
            if (role.system_key === 'admin') {
                return {
                    status: 409,
                    body: {
                        error: 'rule_violation',
                        message:
                            'Die Rolle „Admin“ hat immer alle Rechte und lässt sich nicht ändern.',
                    },
                };
            }
            const body = request.body as Record<string, unknown>;
            if (body['version'] !== role.version) {
                return {
                    status: 409,
                    body: {
                        error: 'version_conflict',
                        message: `Die Rolle „${role.name}“ wurde inzwischen geändert.`,
                        details: { role: roleJson(role) },
                    },
                };
            }
            const fields = fromBody(body);
            if (fields.name !== undefined && taken(fields.name, id)) {
                return nameTaken(fields.name);
            }
            Object.assign(role, fields, { version: role.version + 1 });
            return { status: 200, body: { role: roleJson(role) } };
        };
        handlers[`DELETE ${base}/${String(id)}`] = () => {
            const role = roles.get(id);
            if (role === undefined) {
                return notFound;
            }
            if (role.system_key !== null || role.accounts > 0 || role.invitation_codes > 0) {
                return {
                    status: 409,
                    body: {
                        error: 'rule_violation',
                        message: `Die Rolle „${role.name}“ wird noch verwendet.`,
                        details: {
                            accounts: role.accounts,
                            invitation_codes: role.invitation_codes,
                        },
                    },
                };
            }
            roles.delete(id);
            return { status: 200, body: { deleted_role_id: id } };
        };
    }

    return {
        handlers,
        roles,
        bump(id, change = {}) {
            const role = roles.get(id);
            if (role !== undefined) {
                Object.assign(role, change, { version: role.version + 1 });
            }
        },
    };
}
