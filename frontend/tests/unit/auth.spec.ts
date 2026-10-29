import { describe, expect, it } from 'vitest';
import {
    changePassword,
    endSession,
    fetchSessions,
    login,
    logout,
    logoutOtherSessions,
    parseAuthState,
    parseSessions,
    userLabel,
} from '../../src/api/auth';
import { respond } from './support/fetch';
import { meBody } from './support/me';

const ME = meBody('admin') as {
    user: Record<string, unknown>;
    tenant: unknown;
};

describe('parseAuthState', () => {
    it('liest Konto und Stadt', () => {
        expect(parseAuthState(ME)).toEqual({
            user: {
                id: 7,
                email: 'anna@example.org',
                name: 'Anna Beispiel',
                displayName: 'Anna',
                role: 'admin',
            },
            tenant: { slug: 'test', name: 'Feuerwehr Test' },
        });
    });

    it('Super-Admin ohne Stadt', () => {
        const state = parseAuthState({
            ...ME,
            user: { ...ME.user, role: 'superadmin' },
            tenant: null,
        });
        expect(state?.tenant).toBeNull();
        expect(state?.user.role).toBe('superadmin');
    });

    it.each([
        ['kein Objekt', null],
        ['ohne user', { tenant: null }],
        ['unbekannte Rolle', { ...ME, user: { ...ME.user, role: 'chef' } }],
        ['ID kein int', { ...ME, user: { ...ME.user, id: '7' } }],
        ['Name falscher Typ', { ...ME, user: { ...ME.user, name: 3 } }],
        ['Stadt ohne Namen', { ...ME, tenant: { slug: 'test' } }],
    ])('lehnt ab: %s', (_label, json) => {
        expect(parseAuthState(json)).toBeNull();
    });
});

describe('login und logout', () => {
    it('sendet E-Mail und Passwort im Body', async () => {
        const { fetch, requests } = respond(200, ME);
        const result = await login('anna@example.org', 'geheim-geheim', fetch);
        expect(result.ok).toBe(true);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/auth\/login$/);
        expect(requests[0]?.body).toEqual({ email: 'anna@example.org', password: 'geheim-geheim' });
    });

    it('logout sendet {}', async () => {
        const { fetch, requests } = respond(200, { logged_out: true });
        const result = await logout(fetch);
        expect(result.ok).toBe(true);
        expect(requests[0]?.method).toBe('POST');
        expect(requests[0]?.body).toEqual({});
    });
});

describe('userLabel', () => {
    const user = {
        id: 9,
        email: 'x@example.org',
        name: 'Name',
        displayName: 'Anzeige',
        role: 'user',
    } as const;

    it('bevorzugt den Anzeigenamen', () => {
        expect(userLabel(user)).toBe('Anzeige');
        expect(userLabel({ ...user, displayName: null })).toBe('Name');
        expect(userLabel({ ...user, displayName: null, name: null })).toBe('x@example.org');
        expect(userLabel({ ...user, displayName: null, name: null, email: null })).toBe('Konto #9');
    });
});

/** Sitzung wie AuthJson::session. */
function sessionJson(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id,
        device_label: 'Chrome auf Android',
        created_at: '2026-10-20T08:00:00Z',
        last_seen_at: '2026-10-29T12:55:00Z',
        expires_at: '2027-01-18T08:00:00Z',
        current: false,
        ...overrides,
    };
}

describe('Passwort ändern', () => {
    it('sendet aktuelles und neues Passwort unverändert, liefert die neue Sitzung', async () => {
        const { fetch, requests } = respond(200, ME);
        const result = await changePassword(' alt ', 'neues Passwort 1', fetch);
        expect(result.ok && result.data.user.id).toBe(7);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/auth\/password$/);
        expect(requests[0]?.method).toBe('POST');
        expect(requests[0]?.body).toEqual({
            current_password: ' alt ',
            new_password: 'neues Passwort 1',
        });
    });

    it('403 invalid_current_password kommt als Fehler (keine Abmeldung)', async () => {
        const { fetch } = respond(403, {
            error: 'invalid_current_password',
            message: 'Das aktuelle Passwort ist falsch.',
        });
        const result = await changePassword('falsch', 'neues Passwort 1', fetch);
        expect(result.ok ? null : result.error).toMatchObject({
            code: 'invalid_current_password',
            status: 403,
        });
    });
});

describe('Angemeldete Geräte', () => {
    it('liest die Sitzungen', async () => {
        const { fetch, requests } = respond(200, {
            sessions: [sessionJson(3, { current: true }), sessionJson(5, { device_label: null })],
        });
        const result = await fetchSessions(fetch);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/auth\/sessions$/);
        expect(result.ok ? result.data : null).toEqual([
            {
                id: 3,
                deviceLabel: 'Chrome auf Android',
                createdAt: '2026-10-20T08:00:00Z',
                lastSeenAt: '2026-10-29T12:55:00Z',
                current: true,
            },
            {
                id: 5,
                deviceLabel: null,
                createdAt: '2026-10-20T08:00:00Z',
                lastSeenAt: '2026-10-29T12:55:00Z',
                current: false,
            },
        ]);
    });

    it.each([
        ['kein Objekt', null],
        ['ohne Liste', {}],
        ['ID kein int', { sessions: [sessionJson(1, { id: '1' })] }],
        ['Bezeichnung falscher Typ', { sessions: [sessionJson(1, { device_label: 3 })] }],
        ['Zeit ohne Z', { sessions: [sessionJson(1, { created_at: '2026-10-20 08:00:00' })] }],
        ['current fehlt', { sessions: [sessionJson(1, { current: undefined })] }],
    ])('lehnt ab: %s', (_label, json) => {
        expect(parseSessions(json)).toBeNull();
    });

    it('beendet eine Sitzung per DELETE mit {}', async () => {
        const { fetch, requests } = respond(200, { deleted: true, current: false });
        const result = await endSession(5, fetch);
        expect(result.ok ? result.data : null).toEqual({ current: false });
        expect(requests[0]?.url).toMatch(/\/api\/v1\/auth\/sessions\/5$/);
        expect(requests[0]?.method).toBe('DELETE');
        expect(requests[0]?.body).toEqual({});
    });

    it('meldet alle anderen ab und liefert die Anzahl', async () => {
        const { fetch, requests } = respond(200, { ended_sessions: 2 });
        const result = await logoutOtherSessions(fetch);
        expect(result.ok ? result.data : null).toEqual({ endedSessions: 2 });
        expect(requests[0]?.url).toMatch(/\/api\/v1\/auth\/sessions\/logout-others$/);
        expect(requests[0]?.method).toBe('POST');
        expect(requests[0]?.body).toEqual({});
    });
});
