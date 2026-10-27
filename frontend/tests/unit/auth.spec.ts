import { describe, expect, it } from 'vitest';
import { login, logout, parseAuthState, userLabel } from '../../src/api/auth';
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
