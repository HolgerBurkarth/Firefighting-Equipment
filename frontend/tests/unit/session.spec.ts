import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ApiError } from '../../src/api/client';
import {
    ensureSession,
    isTransientError,
    logout,
    resetSession,
    session,
    sessionCheckError,
    setLoginNotice,
    takeLoginNotice,
} from '../../src/auth/session';
import { respond } from './support/fetch';
import { meBody } from './support/me';

/** Sofort weiter statt zu warten; merkt sich die Wartezeiten. */
function noWait(): { wait: (ms: number) => Promise<void>; waits: number[] } {
    const waits: number[] = [];
    return {
        waits,
        wait: (ms) => {
            waits.push(ms);
            return Promise.resolve();
        },
    };
}

/** fetch, das der Reihe nach die angegebenen Antworten liefert ('abort' = Netzwerkfehler). */
function sequence(...answers: ([number, unknown] | 'abort')[]): typeof fetch {
    let i = 0;
    return (input, init) => {
        const answer = answers[Math.min(i, answers.length - 1)];
        i += 1;
        if (answer === undefined || answer === 'abort') {
            return Promise.reject(new TypeError('Failed to fetch'));
        }
        return respond(answer[0], answer[1]).fetch(input, init);
    };
}

beforeEach(() => {
    resetSession();
    setLoginNotice(null);
});

describe('ensureSession', () => {
    it('fragt auth/me nur einmal, auch bei gleichzeitigen Aufrufen', async () => {
        const { fetch, requests } = respond(200, meBody('user'));
        const [a, b] = await Promise.all([
            ensureSession({ fetchFn: fetch }),
            ensureSession({ fetchFn: fetch }),
        ]);
        await ensureSession({ fetchFn: fetch });
        expect(requests).toHaveLength(1);
        expect(a?.user.role).toBe('user');
        expect(b).toBe(a);
        expect(session.value?.user.id).toBe(7);
    });

    it('ohne Sitzung (401) null, bis zum Zurücksetzen gemerkt', async () => {
        const { fetch, requests } = respond(401, { error: 'not_authenticated', message: 'x' });
        expect(await ensureSession({ fetchFn: fetch })).toBeNull();
        expect(await ensureSession({ fetchFn: fetch })).toBeNull();
        expect(requests).toHaveLength(1);
        resetSession();
        await ensureSession({ fetchFn: fetch });
        expect(requests).toHaveLength(2);
    });

    it('gesperrte Stadt (403) ist eine endgültige Antwort', async () => {
        const { wait, waits } = noWait();
        const fetchFn = sequence([403, { error: 'tenant_inactive', message: 'x' }]);
        expect(await ensureSession({ fetchFn, wait })).toBeNull();
        expect(waits).toEqual([]);
    });

    it('wiederholt bei Störungen, bis eine Antwort kommt', async () => {
        const { wait, waits } = noWait();
        const fetchFn = sequence(
            'abort',
            [503, { error: 'database_unavailable', message: 'x' }],
            [502, '<html>Bad Gateway</html>'],
            [200, '<html>Portal des WLANs</html>'],
            'abort',
            [200, meBody('admin')],
        );
        const state = await ensureSession({ fetchFn, wait });
        expect(state?.user.role).toBe('admin');
        expect(waits).toEqual([2000, 4000, 8000, 15000, 15000]);
        expect(sessionCheckError.value).toBeNull();
    });

    it('zeigt die Störung, solange wiederholt wird', async () => {
        let release: () => void = () => undefined;
        const wait = (): Promise<void> =>
            new Promise((resolve) => {
                release = resolve;
            });
        const fetchFn = sequence('abort', [401, { error: 'not_authenticated', message: 'x' }]);
        const result = ensureSession({ fetchFn, wait });
        await vi.waitFor(() => {
            expect(sessionCheckError.value?.code).toBe('network');
        });
        release();
        expect(await result).toBeNull();
        expect(sessionCheckError.value).toBeNull();
    });

    it('eine Störung führt nie zu „nicht angemeldet“', async () => {
        const wait = (): Promise<void> => new Promise(() => undefined);
        void ensureSession({ fetchFn: sequence('abort'), wait });
        await vi.waitFor(() => {
            expect(sessionCheckError.value).not.toBeNull();
        });
        expect(takeLoginNotice()).toBeNull();
        resetSession();
        expect(sessionCheckError.value).toBeNull();
    });
});

describe('isTransientError', () => {
    const error = (code: string, status: number | null): ApiError => ({
        code,
        status,
        message: null,
        retryAfterSeconds: null,
        details: null,
    });

    it.each([
        ['network', null, true],
        ['invalid_response', 200, true],
        ['invalid_response', 404, true],
        ['database_unavailable', 503, true],
        ['internal_error', 500, true],
        ['not_authenticated', 401, false],
        ['tenant_inactive', 403, false],
        ['tenant_not_found', 404, false],
    ])('%s (%j) → %j', (code, status, expected) => {
        expect(isTransientError(error(code, status))).toBe(expected);
    });
});

describe('logout', () => {
    it('vergisst die Sitzung und hinterlässt den Hinweis', async () => {
        await ensureSession({ fetchFn: respond(200, meBody()).fetch });
        expect(await logout(respond(200, { logged_out: true }).fetch)).toBeNull();
        expect(session.value).toBeNull();
        expect(takeLoginNotice()).toBe('logged_out');
        expect(takeLoginNotice()).toBeNull();
    });

    it('ohne Verbindung bleibt die Sitzung bestehen', async () => {
        await ensureSession({ fetchFn: respond(200, meBody()).fetch });
        const error = await logout(() => Promise.reject(new TypeError('offline')));
        expect(error?.code).toBe('network');
        expect(session.value?.user.id).toBe(7);
    });
});
