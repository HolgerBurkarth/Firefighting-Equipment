import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiRequest, parseRetryAfter, setUnauthorizedHandler } from '../../src/api/client';
import { respond } from './support/fetch';

const parseOk = (json: unknown): { ok: true } | null =>
    typeof json === 'object' && json !== null && 'ok' in json ? { ok: true } : null;

afterEach(() => {
    setUnauthorizedHandler(null);
});

describe('apiRequest', () => {
    it('sendet GET ohne Body an api/v1 im aktuellen Bereich', async () => {
        const { fetch, requests } = respond(200, { ok: true });
        const result = await apiRequest('auth/me', parseOk, { fetchFn: fetch });
        expect(result).toEqual({ ok: true, status: 200, data: { ok: true } });
        expect(requests[0]?.url).toBe('https://www.prodad.de/firefighting/test/api/v1/auth/me');
        expect(requests[0]?.method).toBe('GET');
        expect(requests[0]?.body).toBeUndefined();
    });

    it('sendet ändernde Anfragen als JSON, ohne Body als {}', async () => {
        const { fetch, requests } = respond(200, { ok: true });
        await apiRequest('auth/logout', parseOk, { method: 'POST', fetchFn: fetch });
        await apiRequest('x', parseOk, { method: 'POST', body: { a: 1 }, fetchFn: fetch });
        expect(requests[0]?.headers['Content-Type']).toBe('application/json');
        expect(requests[0]?.body).toEqual({});
        expect(requests[1]?.body).toEqual({ a: 1 });
    });

    it('liest das Fehlerformat mit details', async () => {
        const { fetch } = respond(409, {
            error: 'rule_violation',
            message: 'Nicht leer.',
            details: { devices: 2 },
        });
        const result = await apiRequest('x', parseOk, { fetchFn: fetch });
        expect(result).toEqual({
            ok: false,
            error: {
                code: 'rule_violation',
                message: 'Nicht leer.',
                status: 409,
                retryAfterSeconds: null,
                details: { devices: 2 },
            },
        });
    });

    it('liest Retry-After bei 429', async () => {
        const { fetch } = respond(
            429,
            { error: 'too_many_attempts', message: 'Zu viele.' },
            { 'Retry-After': '240' },
        );
        const result = await apiRequest('x', parseOk, { fetchFn: fetch });
        expect(!result.ok && result.error.retryAfterSeconds).toBe(240);
    });

    it('meldet Netzwerkfehler', async () => {
        const result = await apiRequest('x', parseOk, {
            fetchFn: () => Promise.reject(new TypeError('Failed to fetch')),
        });
        expect(!result.ok && result.error).toMatchObject({ code: 'network', status: null });
    });

    it.each([
        ['kein JSON', 200, '<html>'],
        ['falsche Form', 200, { anders: true }],
        ['Fehler ohne Code', 500, '<html>'],
        ['leere Antwort', 502, undefined],
    ])('meldet unerwartete Antworten (%s)', async (_label, status, body) => {
        const result = await apiRequest('x', parseOk, { fetchFn: respond(status, body).fetch });
        expect(!result.ok && result.error).toMatchObject({ code: 'invalid_response', status });
    });

    it('ruft bei 401 einer geschützten Anfrage den Abmelde-Weg auf', async () => {
        const handler = vi.fn();
        setUnauthorizedHandler(handler);
        const body = { error: 'not_authenticated', message: 'Bitte anmelden.' };
        await apiRequest('devices', parseOk, { fetchFn: respond(401, body).fetch });
        expect(handler).toHaveBeenCalledOnce();
    });

    it('öffentliche Anfragen behandeln 401 selbst', async () => {
        const handler = vi.fn();
        setUnauthorizedHandler(handler);
        const body = { error: 'invalid_credentials', message: 'Falsch.' };
        const result = await apiRequest('auth/login', parseOk, {
            method: 'POST',
            public: true,
            fetchFn: respond(401, body).fetch,
        });
        expect(handler).not.toHaveBeenCalled();
        expect(!result.ok && result.error.code).toBe('invalid_credentials');
    });
});

describe('parseRetryAfter', () => {
    it.each([
        ['120', 120],
        [' 5 ', 5],
        ['0', 0],
        [null, null],
        ['', null],
        ['-3', null],
        ['1.5', null],
        ['Wed, 21 Oct 2026 07:28:00 GMT', null],
    ])('%j → %j', (value, expected) => {
        expect(parseRetryAfter(value)).toBe(expected);
    });
});
