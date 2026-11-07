import { describe, expect, it } from 'vitest';
import {
    authorizeClaude,
    authorizeParams,
    checkAuthorization,
    connectionLabel,
    endClaudeConnection,
    fetchClaudeConnections,
    parseAuthorizeResult,
    parseAuthorizeSummary,
    parseClaudeConnections,
    safeRedirect,
    targetText,
} from '../../src/api/claude';
import { claudeConnectionTimeText } from '../../src/data/users';
import { respond } from './support/fetch';

const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

/** Antwort von authorize/check wie AuthorizeHandler::summary. */
const SUMMARY = {
    tenant: { name: 'Feuerwehr Test' },
    client: { name: 'Claude' },
    target: { host: 'claude.ai', local: false },
    requested_access: 'write',
    requested_access_label: 'Ändern',
};

/** Verbindung wie ClaudeConnectionsHandler. */
const CONNECTION = {
    id: 7,
    client_name: 'Claude',
    target: { host: 'claude.ai', local: false },
    access: 'read',
    access_label: 'Lesen',
    created_at: '2026-11-01T08:00:00Z',
    last_used_at: '2026-11-07T09:55:00Z',
};

describe('Parameter und Rücksprung', () => {
    it('übernimmt die Parameter der Adresse unverändert, je Name den ersten', () => {
        expect(
            authorizeParams('?response_type=code&state=a%20b%26c&scope=read+write&state=zweiter'),
        ).toEqual({ response_type: 'code', state: 'a b&c', scope: 'read write' });
        expect(authorizeParams('')).toEqual({});
    });

    it('folgt nur einem Rücksprung auf genau die redirect_uri der Anfrage', () => {
        const params = { redirect_uri: CALLBACK };
        expect(safeRedirect(`${CALLBACK}?code=ffc_x&state=s`, params)).toBe(
            `${CALLBACK}?code=ffc_x&state=s`,
        );
        expect(safeRedirect('https://evil.example/?code=x', params)).toBeNull();
        expect(safeRedirect(`${CALLBACK}x?code=x`, params)).toBeNull();
        expect(safeRedirect(CALLBACK, params)).toBeNull();
        expect(safeRedirect(`${CALLBACK}?code=x`, {})).toBeNull();
        // Auch eine vom Server gelieferte Adresse gilt nur, wenn die redirect_uri erlaubt ist.
        expect(
            safeRedirect('https://evil.example/cb?code=x', {
                redirect_uri: 'https://evil.example/cb',
            }),
        ).toBeNull();
        const local = { redirect_uri: 'http://localhost:33418/callback' };
        expect(safeRedirect('http://localhost:33418/callback?code=x', local)).not.toBeNull();
        expect(safeRedirect('http://localhost:9999/callback?code=x', local)).toBeNull();
        expect(
            safeRedirect('http://evil@localhost/callback?code=x', {
                redirect_uri: 'http://evil@localhost/callback',
            }),
        ).toBeNull();
    });

    it('nennt das Ziel', () => {
        expect(targetText({ host: 'claude.ai', local: false })).toBe('claude.ai');
        expect(targetText({ host: 'localhost', local: true })).toBe(
            'Claude Code auf diesem Rechner (localhost)',
        );
    });
});

describe('Antworten des Servers', () => {
    it('liest die geprüfte Anfrage', () => {
        expect(parseAuthorizeSummary(SUMMARY)).toEqual({
            tenantName: 'Feuerwehr Test',
            clientName: 'Claude',
            target: { host: 'claude.ai', local: false },
            requestedAccess: 'write',
            requestedAccessLabel: 'Ändern',
        });
        expect(
            parseAuthorizeSummary({ ...SUMMARY, client: { name: null } })?.clientName,
        ).toBeNull();
        expect(parseAuthorizeSummary({ ...SUMMARY, requested_access: 'off' })).toBeNull();
        expect(parseAuthorizeSummary({ ...SUMMARY, target: { host: 'x' } })).toBeNull();
    });

    it('liest Vorschau, Code und Ablehnung', () => {
        expect(
            parseAuthorizeResult({
                access: 'read',
                access_label: 'Lesen',
                user: { display_name: 'Erika' },
            }),
        ).toEqual({
            access: 'read',
            accessLabel: 'Lesen',
            displayName: 'Erika',
            redirectUrl: null,
        });
        expect(parseAuthorizeResult({ redirect_url: `${CALLBACK}?error=access_denied` })).toEqual({
            access: null,
            accessLabel: null,
            displayName: null,
            redirectUrl: `${CALLBACK}?error=access_denied`,
        });
        expect(parseAuthorizeResult({})).toBeNull();
        expect(parseAuthorizeResult({ access: 'admin', access_label: 'x' })).toBeNull();
    });

    it('liest die eigenen Verbindungen', () => {
        expect(parseClaudeConnections({ connections: [CONNECTION] })).toEqual([
            {
                id: 7,
                clientName: 'Claude',
                target: { host: 'claude.ai', local: false },
                access: 'read',
                accessLabel: 'Lesen',
                createdAt: '2026-11-01T08:00:00Z',
                lastUsedAt: '2026-11-07T09:55:00Z',
            },
        ]);
        expect(parseClaudeConnections({ connections: [{ ...CONNECTION, id: '7' }] })).toBeNull();
        expect(
            parseClaudeConnections({ connections: [{ ...CONNECTION, last_used_at: 'gestern' }] }),
        ).toBeNull();
        expect(parseClaudeConnections({})).toBeNull();
    });
});

describe('Anfragen', () => {
    it('sendet Parameter, Entscheidung und Anmeldung an die Routen des Backends', async () => {
        const { fetch: fetchFn, requests } = respond(200, { redirect_url: `${CALLBACK}?x=1` });
        const params = { client_id: 'abc', redirect_uri: CALLBACK };

        await authorizeClaude(params, 'deny', null, fetchFn);
        await authorizeClaude(
            params,
            'allow',
            { email: 'a@example.org', password: ' geheim ' },
            fetchFn,
        );
        await checkAuthorization(params, respond(200, SUMMARY).fetch);
        await endClaudeConnection(7, respond(200, { deleted: true }).fetch);

        expect(requests.map((r) => [r.method, r.url, r.body])).toEqual([
            [
                'POST',
                'https://www.prodad.de/firefighting/test/api/v1/oauth/authorize',
                { params, decision: 'deny' },
            ],
            [
                'POST',
                'https://www.prodad.de/firefighting/test/api/v1/oauth/authorize',
                { params, decision: 'allow', email: 'a@example.org', password: ' geheim ' },
            ],
        ]);
    });

    it('fragt und trennt eigene Verbindungen', async () => {
        const list = respond(200, { connections: [CONNECTION] });
        const result = await fetchClaudeConnections(list.fetch);
        expect(result.ok && result.data.length).toBe(1);
        expect(list.requests[0]?.url).toBe(
            'https://www.prodad.de/firefighting/test/api/v1/auth/claude-connections',
        );

        const end = respond(200, { deleted: true });
        expect((await endClaudeConnection(7, end.fetch)).ok).toBe(true);
        expect([end.requests[0]?.method, end.requests[0]?.url]).toEqual([
            'DELETE',
            'https://www.prodad.de/firefighting/test/api/v1/auth/claude-connections/7',
        ]);
    });
});

describe('Anzeige in „Angemeldete Geräte“', () => {
    it('nennt Ziel und Zeiten', () => {
        const connection = parseClaudeConnections({ connections: [CONNECTION] })?.[0];
        if (connection === undefined) {
            throw new Error('Keine Verbindung.');
        }
        expect(connectionLabel(connection)).toBe('Claude (claude.ai)');
        expect(connectionLabel({ ...connection, target: { host: 'localhost', local: true } })).toBe(
            'Claude Code (localhost)',
        );
        expect(claudeConnectionTimeText(connection, new Date('2026-11-07T10:00:00Z'))).toBe(
            'Zuletzt genutzt vor 5 Min. · verbunden 01.11.2026',
        );
    });
});
