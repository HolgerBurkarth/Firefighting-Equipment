import { describe, expect, it } from 'vitest';
import { deleteRole, postRoleCopy, postRoleImport, postRoleSave } from '../../src/api/roles';
import { respond } from './support/fetch';

/** Wie RoleJson::role. */
function roleJson(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 7,
        name: 'Gerätewart',
        description: null,
        system_key: null,
        all_rights: false,
        editable: true,
        deletable: true,
        areas: { search: 'read' },
        actions: [],
        locations: null,
        device_types: null,
        version: 2,
        created_at: '2026-11-05T08:00:00Z',
        updated_at: '2026-11-05T09:00:00Z',
        usage: { accounts: 0, invitation_codes: 0 },
        ...overrides,
    };
}

describe('API der Rollen', () => {
    it('Speichern sendet Version und ganzen Stand', async () => {
        const { fetch, requests } = respond(200, { role: roleJson({ version: 3 }) });
        const result = await postRoleSave(
            2,
            7,
            2,
            {
                name: 'Wart',
                description: null,
                areas: { search: 'read' },
                actions: [],
                locations: null,
                deviceTypes: [3],
                restrictions: ['single_use_used_only'],
            },
            fetch,
        );
        expect(result.ok && result.data.version).toBe(3);
        expect(requests[0]?.url).toMatch(/api\/v1\/tenants\/2\/roles\/7$/);
        expect(requests[0]?.body).toEqual({
            version: 2,
            name: 'Wart',
            description: null,
            areas: { search: 'read' },
            actions: [],
            locations: null,
            device_types: [3],
            restrictions: ['single_use_used_only'],
        });
    });

    it('Kopieren, Übernehmen und Löschen', async () => {
        const copy = respond(201, { role: roleJson({ id: 8 }) });
        await postRoleCopy(2, 7, copy.fetch);
        expect(copy.requests[0]?.body).toEqual({ copy_of: 7 });

        const imported = respond(201, { role: roleJson({ id: 9 }) });
        await postRoleImport(2, 30, null, imported.fetch);
        await postRoleImport(2, 30, 'Träger', imported.fetch);
        expect(imported.requests.map((r) => r.body)).toEqual([
            { source_role_id: 30 },
            { source_role_id: 30, name: 'Träger' },
        ]);

        const deleted = respond(200, { deleted_role_id: 7 });
        const result = await deleteRole(2, 7, deleted.fetch);
        expect(result.ok && result.data).toBe(7);
        expect(deleted.requests[0]?.method).toBe('DELETE');
    });
});
