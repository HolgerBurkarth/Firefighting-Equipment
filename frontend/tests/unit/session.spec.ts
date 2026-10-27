import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ensureSession,
    logout,
    resetSession,
    session,
    setLoginNotice,
    takeLoginNotice,
} from '../../src/auth/session';
import { respond } from './support/fetch';
import { meBody } from './support/me';

beforeEach(() => {
    resetSession();
    setLoginNotice(null);
});

describe('ensureSession', () => {
    it('fragt auth/me nur einmal, auch bei gleichzeitigen Aufrufen', async () => {
        const { fetch, requests } = respond(200, meBody('user'));
        const [a, b] = await Promise.all([ensureSession(fetch), ensureSession(fetch)]);
        await ensureSession(fetch);
        expect(requests).toHaveLength(1);
        expect(a?.user.role).toBe('user');
        expect(b).toBe(a);
        expect(session.value?.user.id).toBe(7);
    });

    it('ohne Sitzung (401) null, bis zum Zurücksetzen gemerkt', async () => {
        const { fetch, requests } = respond(401, { error: 'not_authenticated', message: 'x' });
        expect(await ensureSession(fetch)).toBeNull();
        expect(await ensureSession(fetch)).toBeNull();
        expect(requests).toHaveLength(1);
        resetSession();
        await ensureSession(fetch);
        expect(requests).toHaveLength(2);
    });

    it('ohne Verbindung: null mit Hinweis, beim nächsten Mal erneut fragen', async () => {
        const failing = vi.fn(() => Promise.reject(new TypeError('offline')));
        expect(await ensureSession(failing)).toBeNull();
        expect(takeLoginNotice()).toBe('network');
        await ensureSession(failing);
        expect(failing).toHaveBeenCalledTimes(2);
    });
});

describe('logout', () => {
    it('vergisst die Sitzung und hinterlässt den Hinweis', async () => {
        await ensureSession(respond(200, meBody()).fetch);
        expect(await logout(respond(200, { logged_out: true }).fetch)).toBeNull();
        expect(session.value).toBeNull();
        expect(takeLoginNotice()).toBe('logged_out');
        expect(takeLoginNotice()).toBeNull();
    });

    it('ohne Verbindung bleibt die Sitzung bestehen', async () => {
        await ensureSession(respond(200, meBody()).fetch);
        const error = await logout(() => Promise.reject(new TypeError('offline')));
        expect(error?.code).toBe('network');
        expect(session.value?.user.id).toBe(7);
    });
});
