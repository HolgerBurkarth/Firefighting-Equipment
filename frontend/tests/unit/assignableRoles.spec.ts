import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
    fetchAssignableRoles,
    parseAssignableRoles,
    type AssignableRole,
    type PermissionCatalog,
} from '../../src/api/roles';
import { parseAdminUser, postBulkRole, type AdminUser } from '../../src/api/users';
import {
    AssignableRoleStore,
    codeRoles,
    defaultRole,
    filterText,
    roleFilterOptions,
    roleNameOf,
    seesText,
} from '../../src/data/assignableRoles';
import { userRows } from '../../src/data/users';
import { respond } from './support/fetch';

/**
 * Rollen zur Auswahl in der Benutzerverwaltung (GET roles, Plan Phase 1, Schritt 1.6): Antwort,
 * Rollenfilter, „Sieht: …“ und „Sieht nur …“, Rollen der Einladungscodes, Sammelaktion.
 */
const CATALOG: unknown = JSON.parse(
    readFileSync(resolve(process.cwd(), '../tests/Fixtures/permission-catalog.json'), 'utf8'),
);

function roleJson(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id,
        name: `Rolle ${String(id)}`,
        description: null,
        system_key: null,
        all_rights: false,
        editable: true,
        deletable: true,
        areas: { search: 'read' },
        actions: ['book.move'],
        locations: null,
        device_types: null,
        version: 1,
        created_at: '2026-11-05T08:00:00Z',
        updated_at: '2026-11-05T08:00:00Z',
        usage: { accounts: 0, invitation_codes: 0 },
        assignable: true,
        assign_blocker: null,
        ...overrides,
    };
}

const RESPONSE = {
    roles: [
        roleJson(1, {
            name: 'Admin',
            system_key: 'admin',
            all_rights: true,
            editable: false,
            deletable: false,
            areas: {},
        }),
        roleJson(2, {
            name: 'Benutzer',
            system_key: 'user',
            deletable: false,
            areas: { search: 'read', due: 'read' },
        }),
        roleJson(3, {
            name: 'Gerätewart',
            locations: [10, 11],
            device_types: [],
            assignable: false,
            assign_blocker: 'Zu viele Rechte.',
        }),
    ],
    catalog: CATALOG,
};

function catalog(): PermissionCatalog {
    const parsed = parseAssignableRoles(RESPONSE);
    if (parsed === null) {
        throw new Error('Antwort nicht lesbar');
    }
    return parsed.catalog;
}

function roles(): readonly AssignableRole[] {
    const parsed = parseAssignableRoles(RESPONSE);
    if (parsed === null) {
        throw new Error('Antwort nicht lesbar');
    }
    return parsed.roles;
}

function user(id: number, roleId: number, overrides: Record<string, unknown> = {}): AdminUser {
    const parsed = parseAdminUser({
        id,
        label: `Person ${String(id)}`,
        email: `p${String(id)}@example.org`,
        name: `Person ${String(id)}`,
        display_name: `Person ${String(id)}`,
        role: roleId === 1 ? 'admin' : 'user',
        role_label: roleId === 1 ? 'Admin' : 'Benutzer',
        role_id: roleId,
        role_name: `Rolle ${String(roleId)}`,
        status: 'active',
        status_label: 'aktiv',
        locked: false,
        email_verified_at: null,
        created_at: '2026-10-01T09:00:00Z',
        invitation_code: null,
        ...overrides,
    });
    if (parsed === null) {
        throw new Error('Konto nicht lesbar');
    }
    return parsed;
}

describe('GET roles', () => {
    it('liest Rollen mit assignable und den Katalog', async () => {
        const mock = respond(200, RESPONSE);
        const result = await fetchAssignableRoles(mock.fetch);
        expect(mock.requests[0]?.url).toMatch(/\/api\/v1\/roles$/);
        expect(
            result.ok &&
                result.data.roles.map((role) => [role.name, role.assignable, role.assignBlocker]),
        ).toEqual([
            ['Admin', true, null],
            ['Benutzer', true, null],
            ['Gerätewart', false, 'Zu viele Rechte.'],
        ]);
        expect(result.ok && result.data.catalog.areas.length).toBeGreaterThan(0);
    });

    it('lehnt widersprüchliche Angaben ab', () => {
        expect(
            parseAssignableRoles({ roles: [roleJson(4, { assignable: false })], catalog: CATALOG }),
        ).toBeNull();
        expect(parseAssignableRoles({ roles: [roleJson(4)] })).toBeNull();
        expect(
            parseAssignableRoles({ roles: [roleJson(4, { assign_blocker: 5 })], catalog: CATALOG }),
        ).toBeNull();
    });
});

describe('Anzeige', () => {
    it('Sieht: … wie im Mockup „Benutzer-Rolle“', () => {
        const catalog = parseAssignableRoles(RESPONSE)?.catalog;
        if (catalog === undefined) {
            throw new Error('Katalog fehlt');
        }
        const [admin, standard] = roles();
        expect(admin && seesText(catalog, admin)).toBe('alles');
        expect(standard && seesText(catalog, standard)).toBe('Suchen, Termine, Profil');
    });

    it('Sieht nur … mit Namen, unbekannte als #ID, leere Auswahl', () => {
        const wart = roles()[2];
        if (wart === undefined) {
            throw new Error('Rolle fehlt');
        }
        expect(
            filterText(
                wart,
                (id) => (id === 10 ? 'HLF 20' : null),
                () => null,
            ),
        ).toBe('Sieht nur: HLF 20, #11; kein Ausrüstungstyp');
        expect(
            filterText(
                roles()[1] ?? wart,
                () => null,
                () => null,
            ),
        ).toBeNull();
    });

    it('Rollenfilter: Alle und je Rolle mit Konten, ohne wartende; Liste nach Rolle', () => {
        const users = [user(1, 1), user(2, 2), user(3, 2), user(4, 3, { status: 'pending' })];
        expect(roleFilterOptions(users, roles())).toEqual([
            { roleId: null, label: 'Alle', count: 3 },
            { roleId: 1, label: 'Admin', count: 1 },
            { roleId: 2, label: 'Benutzer', count: 2 },
        ]);
        expect(userRows(users, '', 'name', 'asc', 2).map((u) => u.id)).toEqual([2, 3]);
        expect(userRows(users, '', 'name', 'asc', null).map((u) => u.id)).toEqual([1, 2, 3]);
        expect(roleNameOf(user(5, 3))).toBe('Rolle 3');
    });

    it('Einladungscodes: alle Rollen außer Admin, vorgewählt Benutzer', () => {
        expect(codeRoles(roles()).map((role) => role.name)).toEqual(['Benutzer', 'Gerätewart']);
        expect(defaultRole(roles())?.name).toBe('Benutzer');
        expect(defaultRole([])).toBeNull();
    });
});

describe('Store und Sammelaktion', () => {
    it('Store behält den Stand bei einem Fehler', async () => {
        let ok = true;
        const store = new AssignableRoleStore(() =>
            Promise.resolve(
                ok
                    ? {
                          ok: true as const,
                          status: 200,
                          data: {
                              roles: roles(),
                              catalog: catalog(),
                          },
                      }
                    : {
                          ok: false as const,
                          error: {
                              code: 'network',
                              message: 'Keine Verbindung.',
                              status: null,
                              retryAfterSeconds: null,
                              details: null,
                          },
                      },
            ),
        );
        await store.refresh('changed');
        expect(store.state.data?.roles).toHaveLength(3);
        ok = false;
        await store.refresh('changed');
        expect(store.state.data?.roles).toHaveLength(3);
        expect(store.state.error?.code).toBe('network');
        store.clear();
        expect(store.state.data).toBeNull();
    });

    it('POST users/bulk/role mit user_ids und role_id', async () => {
        const mock = respond(200, {
            results: [],
            summary: { total: 0, applied: 0, unchanged: 0, errors: 0 },
        });
        const result = await postBulkRole([4, 5], 3, mock.fetch);
        expect(result.ok).toBe(true);
        expect(mock.requests[0]?.url).toMatch(/\/api\/v1\/users\/bulk\/role$/);
        expect(mock.requests[0]?.body).toEqual({ user_ids: [4, 5], role_id: 3 });
    });
});
