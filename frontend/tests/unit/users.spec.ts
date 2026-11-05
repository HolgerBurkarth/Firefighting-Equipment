import { beforeEach, describe, expect, it } from 'vitest';
import {
    approveUser,
    changeUserDisplayName,
    changeUserRole,
    deleteUser,
    parseAdminUser,
    parseBulkRoleResponse,
    rejectUser,
    setUserLocked,
    type AdminUser,
} from '../../src/api/users';
import {
    bulkRoleText,
    createdText,
    displayNameProblem,
    nameHint,
    pendingText,
    pendingUsers,
    registrationText,
    selectable,
    selectedText,
    statusText,
    userChanges,
    userRows,
} from '../../src/data/users';
import { respond, type RecordedRequest } from './support/fetch';

/** Konto wie UserJson::user. */
function json(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id,
        label: `Person ${String(id)}`,
        email: `person${String(id)}@example.org`,
        name: `Person ${String(id)}`,
        display_name: `Person ${String(id)}`,
        role: 'user',
        role_label: 'Benutzer',
        role_id: 2,
        role_name: 'Benutzer',
        status: 'active',
        status_label: 'aktiv',
        locked: false,
        email_verified_at: '2026-10-01T10:00:00Z',
        created_at: '2026-10-01T09:00:00Z',
        invitation_code: null,
        ...overrides,
    };
}

function user(id: number, overrides: Record<string, unknown> = {}): AdminUser {
    const parsed = parseAdminUser(json(id, overrides));
    if (parsed === null) {
        throw new Error('ungültiges Konto');
    }
    return parsed;
}

describe('Aufrufe der Benutzerverwaltung', () => {
    let requests: RecordedRequest[] = [];
    let fetchFn: typeof fetch = fetch;

    beforeEach(() => {
        const mock = respond(200, { user: json(4) });
        requests = mock.requests;
        fetchFn = mock.fetch;
    });

    it.each([
        [
            'approve',
            () => approveUser(4, { displayName: 'Max', roleId: 9 }, fetchFn),
            { display_name: 'Max', role_id: 9 },
        ],
        ['reject', () => rejectUser(4, fetchFn), {}],
        ['lock', () => setUserLocked(4, true, fetchFn), {}],
        ['unlock', () => setUserLocked(4, false, fetchFn), {}],
        ['role', () => changeUserRole(4, 5, fetchFn), { role_id: 5 }],
        ['display-name', () => changeUserDisplayName(4, 'Maxi', fetchFn), { display_name: 'Maxi' }],
    ] as const)('POST users/4/%s', async (action, call, body) => {
        const result = await call();
        expect(requests[0]?.url).toMatch(new RegExp(`/api/v1/users/4/${action}$`));
        expect(requests[0]?.method).toBe('POST');
        expect(requests[0]?.body).toEqual(body);
        expect(result).toMatchObject({ ok: true, data: { id: 4, label: 'Person 4' } });
    });

    it('DELETE users/4 mit {}', async () => {
        const result = await deleteUser(4, fetchFn);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/users\/4$/);
        expect(requests[0]?.method).toBe('DELETE');
        expect(requests[0]?.body).toEqual({});
        expect(result.ok).toBe(true);
    });

    it('409 rule_violation als Fehler', async () => {
        const { fetch: conflict } = respond(409, {
            error: 'rule_violation',
            message: 'Sie können Ihr eigenes Konto nicht sperren.',
        });
        expect(await setUserLocked(4, true, conflict)).toMatchObject({
            ok: false,
            error: {
                code: 'rule_violation',
                message: 'Sie können Ihr eigenes Konto nicht sperren.',
            },
        });
    });

    it('Antwort ohne user ist invalid_response', async () => {
        const { fetch: wrong } = respond(200, { account: json(4) });
        expect(await rejectUser(4, wrong)).toMatchObject({
            ok: false,
            error: { code: 'invalid_response' },
        });
    });
});

describe('Gruppen, Suche und Sortierung', () => {
    const users = [
        user(1, { label: 'Anna Berg', display_name: 'Anna Berg', email: 'anna@x.de' }),
        user(2, {
            label: 'Zoë Kraus',
            display_name: 'Zoë Kraus',
            email: 'zk@x.de',
            role: 'admin',
            role_label: 'Admin',
            created_at: '2026-09-01T09:00:00Z',
        }),
        user(3, {
            label: 'Neu 2',
            name: 'Neu 2',
            status: 'pending',
            status_label: 'wartet auf Freischaltung',
            display_name: null,
            created_at: '2026-10-20T09:00:00Z',
        }),
        user(4, {
            label: 'Neu 1',
            name: 'Neu 1',
            status: 'pending',
            status_label: 'wartet auf Freischaltung',
            display_name: null,
            created_at: '2026-10-10T09:00:00Z',
        }),
        user(5, { label: 'Bernd', display_name: 'Bernd', name: 'Bernd', locked: true }),
        user(6, {
            label: 'Uwe',
            status: 'unverified',
            status_label: 'unbestätigt',
            display_name: null,
            name: 'Uwe',
            email: 'uwe@x.de',
        }),
    ];

    it('pendingUsers: nur wartende, älteste zuerst', () => {
        expect(pendingUsers(users).map((u) => u.id)).toEqual([4, 3]);
    });

    it('userRows: ohne wartende, nach Name natürlich', () => {
        expect(userRows(users, '', 'name', 'asc').map((u) => u.id)).toEqual([1, 5, 6, 2]);
        expect(userRows(users, '', 'name', 'desc').map((u) => u.id)).toEqual([2, 6, 5, 1]);
    });

    it('userRows: Suche mit UND in Anzeigename, Name und E-Mail, ohne Akzente', () => {
        expect(userRows(users, 'zoe', 'name', 'asc').map((u) => u.id)).toEqual([2]);
        expect(userRows(users, 'x.de anna', 'name', 'asc').map((u) => u.id)).toEqual([1]);
        expect(userRows(users, 'UWE', 'name', 'asc').map((u) => u.id)).toEqual([6]);
        expect(userRows(users, 'neu', 'name', 'asc')).toEqual([]);
    });

    it('userRows: Rolle (Admins zuerst), Status, Registrierung; Gleichstand nach Name', () => {
        expect(userRows(users, '', 'role', 'asc').map((u) => u.id)).toEqual([2, 1, 5, 6]);
        expect(userRows(users, '', 'status', 'asc').map((u) => u.id)).toEqual([1, 2, 6, 5]);
        expect(userRows(users, '', 'status', 'desc').map((u) => u.id)).toEqual([5, 6, 1, 2]);
        expect(userRows(users, '', 'created', 'asc')[0]?.id).toBe(2);
    });

    it('statusText: gesperrt vor dem Status', () => {
        expect(users.map(statusText)).toEqual([
            'aktiv',
            'aktiv',
            'wartet auf Freischaltung',
            'wartet auf Freischaltung',
            'gesperrt',
            'E-Mail unbestätigt',
        ]);
        expect(statusText(user(7, { status: 'invited', status_label: 'eingeladen' }))).toBe(
            'eingeladen',
        );
    });

    it('nameHint: Name aus der Registrierung, wenn der Anzeigename abweicht', () => {
        expect(nameHint(user(1, { name: 'Anna Maria Berg', display_name: 'Anna' }))).toBe(
            'Anna Maria Berg',
        );
        expect(nameHint(user(1))).toBeNull();
        expect(nameHint(user(1, { name: null }))).toBeNull();
    });
});

describe('Bearbeiten und Texte', () => {
    it('userChanges: nur Änderungen, Name getrimmt; Name nur bei aktiven Konten', () => {
        const active = user(1, { display_name: 'Anna' });
        expect(userChanges(active, ' Anna ', 2)).toEqual({});
        expect(userChanges(active, 'Anna B.', 1)).toEqual({
            displayName: 'Anna B.',
            roleId: 1,
        });
        expect(userChanges(active, 'Anna', null)).toEqual({});
        const invited = user(2, {
            status: 'invited',
            display_name: null,
            name: null,
            role: 'admin',
            role_id: 1,
        });
        expect(userChanges(invited, 'egal', 1)).toEqual({});
        expect(userChanges(invited, 'egal', 2)).toEqual({ roleId: 2 });
    });

    it('Rolle und Einladungscode aus der Antwort; ältere Antworten ohne die Felder', () => {
        const coded = user(3, {
            role_id: 7,
            role_name: 'Gerätewart',
            invitation_code: { id: 4, label: 'Aushang' },
        });
        expect([coded.roleId, coded.roleName, coded.invitationCode]).toEqual([
            7,
            'Gerätewart',
            { id: 4, label: 'Aushang' },
        ]);
        const old = json(3);
        delete old['role_id'];
        delete old['role_name'];
        delete old['invitation_code'];
        expect(parseAdminUser(old)?.roleId).toBeNull();
        expect(parseAdminUser(json(3, { role_id: 'x' }))).toBeNull();
        expect(parseAdminUser(json(3, { invitation_code: { id: 4 } }))).toBeNull();
    });

    it('registrationText nennt den Aushang', () => {
        expect(
            registrationText(user(3, { invitation_code: { id: 4, label: 'Geräteträger' } })),
        ).toBe(
            'Registriert am 01.10.2026 mit dem Aushang „Geräteträger“; die Rolle ist vorausgewählt.',
        );
        expect(registrationText(user(3, { invitation_code: { id: 4, label: null } }))).toBe(
            'Registriert am 01.10.2026',
        );
    });

    it('Mehrfachauswahl: nicht das eigene Konto; Texte', () => {
        expect(selectable(user(3), 3)).toBe(false);
        expect(selectable(user(3), 7)).toBe(true);
        expect(selectedText(1)).toBe('1 Konto ausgewählt');
        expect(selectedText(3)).toBe('3 Konten ausgewählt');
        const results = parseBulkRoleResponse({
            results: [
                { id: 1, outcome: 'applied', user: json(1), error: null },
                { id: 2, outcome: 'unchanged', user: json(2), error: null },
                {
                    id: 3,
                    outcome: 'error',
                    user: null,
                    error: { error: 'not_found', message: 'Kein Konto.' },
                },
            ],
            summary: { total: 3, applied: 1, unchanged: 1, errors: 1 },
        });
        expect(results).not.toBeNull();
        expect(bulkRoleText('Gerätewart', results ?? [], 2)).toBe(
            'Rolle „Gerätewart“: 1 Konto geändert, 1 unverändert, 3 nicht möglich.',
        );
        expect(bulkRoleText('Benutzer', [], 0)).toBe('Rolle „Benutzer“: 0 Konten geändert.');
        expect(
            parseBulkRoleResponse({
                results: [{ id: 1, outcome: 'error', user: null, error: null }],
            }),
        ).toBeNull();
    });

    it('displayNameProblem wie PersonName', () => {
        expect(displayNameProblem('  ')).toBe('Bitte einen Namen eingeben.');
        expect(displayNameProblem('x'.repeat(100))).toBeNull();
        expect(displayNameProblem('x'.repeat(101))).toBe('Höchstens 100 Zeichen (jetzt 101).');
        expect(displayNameProblem('a\u0007b')).toBe('Keine Zeilenumbrüche oder Steuerzeichen.');
    });

    it('pendingText und createdText', () => {
        expect(pendingText(1)).toBe('1 neue Anmeldung wartet auf Freischaltung');
        expect(pendingText(2)).toBe('2 neue Anmeldungen warten auf Freischaltung');
        expect(createdText(user(1, { created_at: '2026-10-12T10:00:00Z' }))).toBe('12.10.2026');
    });
});
