import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    apiDownload,
    apiRequest,
    downloadFileName,
    parseApiVersion,
    parseRetryAfter,
    setOutdatedHandler,
    setUnauthorizedHandler,
} from '../../src/api/client';
import { respond } from './support/fetch';

const parseOk = (json: unknown): { ok: true } | null =>
    typeof json === 'object' && json !== null && 'ok' in json ? { ok: true } : null;

afterEach(() => {
    setUnauthorizedHandler(null);
    setOutdatedHandler(null);
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

    it('sendet bei jedem Aufruf die Vertragsnummer X-Client-Api', async () => {
        const { fetch, requests } = respond(200, { ok: true });
        await apiRequest('auth/me', parseOk, { fetchFn: fetch });
        await apiRequest('auth/logout', parseOk, { method: 'POST', fetchFn: fetch });
        await apiRequest('x', parseOk, { method: 'DELETE', fetchFn: fetch });
        expect(requests.map((request) => request.headers['X-Client-Api'])).toEqual(['1', '1', '1']);
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

describe('apiDownload', () => {
    it('lädt die Datei mit X-Client-Api und dem Namen aus Content-Disposition', async () => {
        const { fetch, requests } = respond(200, '﻿Kennung;Gerätetyp\r\nM1;Maske\r\n', {
            'Content-Type': 'text/csv; charset=utf-8',
            'Content-Disposition': 'attachment; filename="geraete-test-2026-10-30.csv"',
        });
        const result = await apiDownload('devices/export?status=defective', 'geraete.csv', fetch);
        expect(result.ok).toBe(true);
        expect(result.ok && result.data.fileName).toBe('geraete-test-2026-10-30.csv');
        expect(result.ok && (await result.data.blob.text())).toContain('M1;Maske');
        expect(requests[0]?.url).toBe(
            'https://www.prodad.de/firefighting/test/api/v1/devices/export?status=defective',
        );
        expect(requests[0]?.headers['X-Client-Api']).toBe('1');
    });

    it('Fehler kommen als ApiError, 401 meldet ab', async () => {
        const unauthorized = vi.fn();
        setUnauthorizedHandler(unauthorized);
        const { fetch } = respond(401, { error: 'not_authenticated', message: 'Bitte anmelden.' });
        const result = await apiDownload('devices/export', 'geraete.csv', fetch);
        expect(!result.ok && result.error.code).toBe('not_authenticated');
        expect(unauthorized).toHaveBeenCalledOnce();

        const failing: typeof globalThis.fetch = () => Promise.reject(new TypeError('offline'));
        const offline = await apiDownload('devices/export', 'geraete.csv', failing);
        expect(!offline.ok && offline.error.code).toBe('network');
    });

    it('Dateiname nur in der Form des Backends, sonst der Ersatz', () => {
        expect(downloadFileName('attachment; filename="a-1.csv"', 'x.csv')).toBe('a-1.csv');
        expect(downloadFileName('attachment; filename="../böse.csv"', 'x.csv')).toBe('x.csv');
        expect(downloadFileName(null, 'x.csv')).toBe('x.csv');
    });
});

describe('Vertragsnummer des Servers (X-Api-Version) und client_outdated', () => {
    it('meldet client_outdated sofort, auch bei Downloads', async () => {
        const outdated = vi.fn();
        setOutdatedHandler(outdated);
        const body = {
            error: 'client_outdated',
            message: 'Diese Version der App ist veraltet – bitte aktualisieren.',
            details: { min_client_api: 2 },
        };
        const result = await apiRequest('auth/me', parseOk, {
            fetchFn: respond(409, body, { 'X-Api-Version': '2' }).fetch,
        });
        expect(!result.ok && result.error.code).toBe('client_outdated');
        expect(!result.ok && result.error.details).toEqual({ min_client_api: 2 });
        const download = await apiDownload(
            'devices/export',
            'geraete.csv',
            respond(409, body).fetch,
        );
        expect(!download.ok && download.error.code).toBe('client_outdated');
        expect(outdated.mock.calls).toEqual([['client_outdated'], ['client_outdated']]);
    });

    it('meldet eine neuere Nummer des Servers, auch bei Erfolg und anderen Fehlern', async () => {
        const outdated = vi.fn();
        setOutdatedHandler(outdated);
        await apiRequest('x', parseOk, {
            fetchFn: respond(200, { ok: true }, { 'X-Api-Version': '2' }).fetch,
        });
        await apiRequest('x', parseOk, {
            fetchFn: respond(404, { error: 'not_found', message: 'x' }, { 'X-Api-Version': '2' })
                .fetch,
        });
        await apiDownload(
            'devices/export',
            'g.csv',
            respond(200, 'a;b', { 'X-Api-Version': '2' }).fetch,
        );
        expect(outdated.mock.calls).toEqual([['newer_api'], ['newer_api'], ['newer_api']]);
    });

    it('meldet nichts bei gleicher, kleinerer oder fehlender Nummer', async () => {
        const outdated = vi.fn();
        setOutdatedHandler(outdated);
        for (const headers of [{ 'X-Api-Version': '1' }, { 'X-Api-Version': '0' }, {}]) {
            await apiRequest('x', parseOk, { fetchFn: respond(200, { ok: true }, headers).fetch });
        }
        expect(outdated).not.toHaveBeenCalled();
    });

    it.each([
        ['1', 1],
        [' 12 ', 12],
        ['0', null],
        ['01', null],
        ['1.0', null],
        ['zwei', null],
        ['', null],
        [null, null],
    ])('X-Api-Version %j → %j', (value, expected) => {
        expect(parseApiVersion(value)).toBe(expected);
    });
});
