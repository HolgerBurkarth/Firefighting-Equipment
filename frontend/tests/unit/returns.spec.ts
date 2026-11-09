import { describe, expect, it } from 'vitest';
import {
    checkReturn,
    parseLoans,
    postProcessReturns,
    postReturn,
    renewReturnKey,
    revokeReturnKey,
} from '../../src/api/loans';
import { returnAddress, returnLink, returnProblem, RETURN_CONDITIONS } from '../../src/data/loans';
import { deviceJson } from './support/devices';
import { respond } from './support/fetch';

const BASE = {
    today: '2026-11-09',
    summary: { open: 0, overdue: 0 },
    shown: 0,
    loans: [],
    borrowers: [],
    labels: { borrower: 'Ausgeliehen an', returns: 'Rückgabe' },
};

describe('Rückgabe: Liste', () => {
    it('liest Rückgabestellen mit Aushang und die Ausrüstung dort', () => {
        const state = parseLoans({
            ...BASE,
            returns: [
                { id: 7, name: 'Rückgabe', link_created_at: '2026-11-09T09:00:00Z' },
                { id: 8, name: 'Kiste', link_created_at: null },
            ],
            in_returns: [deviceJson(1)],
        });
        expect(state?.returns).toEqual([
            { id: 7, name: 'Rückgabe', linkCreatedAt: '2026-11-09T09:00:00Z' },
            { id: 8, name: 'Kiste', linkCreatedAt: null },
        ]);
        expect(state?.inReturns.map((device) => device.identifier)).toEqual(['M1']);
        expect(state?.returnsLabel).toBe('Rückgabe');
    });

    it('ältere Server ohne in_returns und ohne Aushang-Angabe', () => {
        const state = parseLoans({ ...BASE, returns: [{ id: 7, name: 'Rückgabe' }] });
        expect(state?.returns[0]?.linkCreatedAt).toBeNull();
        expect(state?.inReturns).toEqual([]);
    });

    it.each([
        ['Aushang kein Text', { ...BASE, returns: [{ id: 7, name: 'x', link_created_at: 5 }] }],
        ['Ausrüstung unbrauchbar', { ...BASE, returns: [], in_returns: [{ id: 1 }] }],
    ])('lehnt ab: %s', (_label, json) => {
        expect(parseLoans(json)).toBeNull();
    });
});

describe('Rückgabe: API', () => {
    it('Aushang erneuern und widerrufen', async () => {
        const renew = respond(200, { key: 'abc' });
        const result = await renewReturnKey(7, renew.fetch);
        expect(renew.requests[0]?.url).toMatch(/\/api\/v1\/return-points\/7\/renew$/);
        expect(result.ok && result.data).toBe('abc');
        const revoke = respond(200, { revoked: true });
        const revoked = await revokeReturnKey(7, revoke.fetch);
        expect(revoke.requests[0]?.url).toMatch(/\/api\/v1\/return-points\/7\/revoke$/);
        expect(revoked.ok && revoked.data).toBe(true);
    });

    it('Rückgaben bearbeiten sendet nur Nummer und Version', async () => {
        const answer = { results: [], summary: { total: 0, applied: 0, unchanged: 0, errors: 0 } };
        const store = respond(200, answer);
        await postProcessReturns('to-store', [{ id: 1, version: 4 }], undefined, store.fetch);
        expect(store.requests[0]?.url).toMatch(/\/api\/v1\/loans\/to-store$/);
        expect(store.requests[0]?.body).toEqual({ devices: [{ id: 1, version: 4 }] });
        const collect = respond(200, answer);
        await postProcessReturns('to-collection', [{ id: 1, version: 4 }], 'Montag', collect.fetch);
        expect(collect.requests[0]?.url).toMatch(/\/api\/v1\/loans\/to-collection$/);
        expect(collect.requests[0]?.body).toEqual({
            devices: [{ id: 1, version: 4 }],
            comment: 'Montag',
        });
    });

    it('öffentliche Aufrufe senden Schlüssel und Code, ohne Anmeldung', async () => {
        const check = respond(200, {
            identifier: 'M1',
            device_type: 'Maske',
            location: 'Rückgabe',
        });
        const found = await checkReturn('K', 'M1', check.fetch);
        expect(check.requests[0]?.url).toMatch(/\/api\/v1\/returns\/check$/);
        expect(check.requests[0]?.body).toEqual({ key: 'K', code: 'M1' });
        expect(found.ok && found.data).toEqual({
            identifier: 'M1',
            deviceType: 'Maske',
            location: 'Rückgabe',
        });
        const give = respond(200, {
            identifier: 'M1',
            device_type: 'Maske',
            location: 'Rückgabe',
            status_label: 'defekt',
        });
        const done = await postReturn(
            { key: 'K', code: 'M1', name: 'Herr Maier', condition: 'defective' },
            give.fetch,
        );
        expect(give.requests[0]?.url).toMatch(/\/api\/v1\/returns$/);
        expect(give.requests[0]?.body).toEqual({
            key: 'K',
            code: 'M1',
            name: 'Herr Maier',
            condition: 'defective',
        });
        expect(done.ok && done.data.statusLabel).toBe('defekt');
    });

    it('401 einer öffentlichen Rückgabe meldet nicht ab', async () => {
        const denied = respond(401, { error: 'not_authenticated', message: 'x' });
        const result = await checkReturn('K', 'M1', denied.fetch);
        expect(result.ok).toBe(false);
    });
});

describe('Rückgabe: Link und Eingabe', () => {
    it('der Schlüssel steht nur im Fragment', () => {
        const base = 'https://www.prodad.de/firefighting/test/';
        expect(returnLink('K1', base)).toBe(`${base}rueckgabe#schluessel=K1`);
        expect(returnAddress(base)).toBe('www.prodad.de/firefighting/test/rueckgabe');
        expect(new URL(returnLink('K1', base)).search).toBe('');
    });

    it('Prüfung', () => {
        expect(returnProblem('M1', '')).toBe('Bitte geben Sie Ihren Namen ein.');
        expect(returnProblem('M1', 'x'.repeat(101))).toContain('100 Zeichen');
        expect(returnProblem('', 'Herr Maier')).toContain('Nummer');
        expect(returnProblem('M1', 'Herr Maier')).toBeNull();
    });

    it('Zustände wie im Backend', () => {
        expect(RETURN_CONDITIONS.map((entry) => entry.value)).toEqual(['ok', 'used', 'defective']);
    });
});
