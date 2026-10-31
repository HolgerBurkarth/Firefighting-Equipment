import { describe, expect, it } from 'vitest';
import type { ApiResult } from '../../src/api/client';
import {
    createInvitationCode,
    fetchInvitationCodes,
    parseCreatedInvitationCode,
    parseInvitationCode,
    parseInvitationCodeList,
    revokeInvitationCode,
    type InvitationCode,
} from '../../src/api/invitationCodes';
import { InvitationCodeStore } from '../../src/data/invitationCodeStore';
import { respond } from './support/fetch';

/** Wie InvitationJson::code. */
function codeJson(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 3,
        label: 'Dienstabend',
        status: 'active',
        status_label: 'aktiv',
        use_count: 2,
        max_uses: 50,
        expires_at: '2026-11-30T13:05:00Z',
        created_at: '2026-10-31T13:05:00Z',
        revoked_at: null,
        created_by: { id: 7, label: 'Thomas Keller' },
        ...overrides,
    };
}

describe('Antworten der API', () => {
    it('liest einen Code', () => {
        expect(parseInvitationCode(codeJson())).toEqual({
            id: 3,
            label: 'Dienstabend',
            status: 'active',
            statusLabel: 'aktiv',
            useCount: 2,
            maxUses: 50,
            expiresAt: '2026-11-30T13:05:00Z',
            createdAt: '2026-10-31T13:05:00Z',
            revokedAt: null,
            createdBy: { id: 7, label: 'Thomas Keller' },
        });
        expect(parseInvitationCode(codeJson({ label: null }))?.label).toBeNull();
    });

    it.each([
        ['status', 'unknown'],
        ['use_count', -1],
        ['max_uses', 1.5],
        ['expires_at', '30.11.2026'],
        ['revoked_at', 'gestern'],
        ['created_by', { id: 7 }],
        ['label', 5],
    ])('lehnt %s = %j ab', (field, value) => {
        expect(parseInvitationCode(codeJson({ [field]: value }))).toBeNull();
    });

    it('Liste und neuer Code (Klartext nur als XXXX-XXXX-XXXX)', () => {
        expect(parseInvitationCodeList({ invitation_codes: [codeJson()] })).toHaveLength(1);
        expect(parseInvitationCodeList({ invitation_codes: [codeJson(), {}] })).toBeNull();
        expect(parseInvitationCodeList({})).toBeNull();
        expect(
            parseCreatedInvitationCode({ invitation_code: codeJson(), code: 'ABCD-EFGH-JKMN' })
                ?.code,
        ).toBe('ABCD-EFGH-JKMN');
        for (const code of ['ABCDEFGHJKMN', 'abcd-efgh-jkmn', 'ABCD-EFGH-JKM0', null]) {
            expect(parseCreatedInvitationCode({ invitation_code: codeJson(), code })).toBeNull();
        }
    });

    it('sendet Anfragen wie die Handler erwarten', async () => {
        const { fetch, requests } = respond(200, {
            invitation_code: codeJson(),
            code: 'ABCD-EFGH-JKMN',
            invitation_codes: [],
        });
        expect((await fetchInvitationCodes(fetch)).ok).toBe(true);
        expect(
            (await createInvitationCode({ label: null, validDays: 30, maxUses: 50 }, fetch)).ok,
        ).toBe(true);
        await createInvitationCode({ label: 'Wache', validDays: 1, maxUses: 1 }, fetch);
        expect((await revokeInvitationCode(3, fetch)).ok).toBe(true);
        expect(
            requests.map((request) => [
                request.url.replace(/^.*api\/v1\//, ''),
                request.method,
                request.body,
            ]),
        ).toEqual([
            ['invitation-codes', 'GET', undefined],
            ['invitation-codes', 'POST', { valid_days: 30, max_uses: 50 }],
            ['invitation-codes', 'POST', { label: 'Wache', valid_days: 1, max_uses: 1 }],
            ['invitation-codes/3/revoke', 'POST', {}],
        ]);
    });
});

describe('InvitationCodeStore', () => {
    it('lädt die Liste, ein Fehler behält die bisherige, clear leert', async () => {
        const code = parseInvitationCode(codeJson());
        const results: ApiResult<InvitationCode[]>[] = [
            { ok: true, status: 200, data: code === null ? [] : [code] },
            {
                ok: false,
                error: {
                    code: 'network',
                    message: 'Keine Verbindung.',
                    status: null,
                    retryAfterSeconds: null,
                    details: null,
                },
            },
        ];
        const failure = results[1];
        const store = new InvitationCodeStore(() => {
            const next = results.shift() ?? failure;
            if (next === undefined) {
                throw new Error('keine Antwort');
            }
            return Promise.resolve(next);
        });
        expect(store.state.codes).toBeNull();
        await store.refresh('changed');
        expect(store.state.codes).toHaveLength(1);
        await store.refresh('changed');
        expect(store.state.codes).toHaveLength(1);
        expect(store.state.error?.code).toBe('network');
        store.clear();
        expect(store.state).toEqual({ codes: null, loading: false, error: null });
    });
});
