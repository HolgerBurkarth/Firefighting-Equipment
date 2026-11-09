import { describe, expect, it } from 'vitest';
import { fetchLoans, parseLoans, postLend } from '../../src/api/loans';
import {
    dueText,
    germanDate,
    groupLoans,
    lendProblem,
    loansSummary,
    loansTile,
} from '../../src/data/loans';
import { deviceJson } from './support/devices';
import { respond } from './support/fetch';

function loanJson(id: number, borrower: { id: number; name: string }, overrides = {}) {
    return {
        id,
        device: deviceJson(id),
        borrower,
        contact: null,
        due_back_on: null,
        overdue: false,
        days_overdue: 0,
        comment: null,
        lent_at: '2026-11-01T08:00:00Z',
        lent_by: { id: 1, name: 'Thomas' },
        ...overrides,
    };
}

const STATE = {
    today: '2026-11-09',
    summary: { open: 3, overdue: 1 },
    shown: 3,
    loans: [
        loanJson(
            1,
            { id: 5, name: 'FF Nachbar' },
            { due_back_on: '2026-11-01', overdue: true, days_overdue: 8 },
        ),
        loanJson(2, { id: 5, name: 'FF Nachbar' }),
        loanJson(3, { id: 6, name: 'Schule' }, { contact: 'Frau Weber' }),
    ],
    borrowers: [{ id: 5, name: 'FF Nachbar' }],
    returns: [{ id: 7, name: 'Rückgabe' }],
    labels: { borrower: 'Ausgeliehen an', returns: 'Rückgabe' },
};

describe('Verleih: API', () => {
    it('liest die Liste', () => {
        const state = parseLoans(STATE);
        expect(state?.open).toBe(3);
        expect(state?.overdue).toBe(1);
        expect(state?.loans.map((loan) => loan.device.identifier)).toEqual(['M1', 'M2', 'M3']);
        expect(state?.loans[0]?.dueBackOn).toBe('2026-11-01');
        expect(state?.loans[2]?.contact).toBe('Frau Weber');
        expect(state?.borrowerLabel).toBe('Ausgeliehen an');
    });

    it.each([
        ['kein Objekt', 'x'],
        ['Zusammenfassung fehlt', { ...STATE, summary: null }],
        ['Verleih unbrauchbar', { ...STATE, loans: [{ id: 1 }] }],
        ['Leihnehmer unbrauchbar', { ...STATE, borrowers: [{ id: 'x' }] }],
        ['Namen fehlen', { ...STATE, labels: { borrower: 1 } }],
    ])('lehnt ab: %s', (_label, json) => {
        expect(parseLoans(json)).toBeNull();
    });

    it('GET loans', async () => {
        const { fetch, requests } = respond(200, STATE);
        const result = await fetchLoans(fetch);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/loans$/);
        expect(result.ok).toBe(true);
    });

    it('POST loans sendet nur Gesetztes', async () => {
        const answer = { results: [], summary: { total: 0, applied: 0, unchanged: 0, errors: 0 } };
        const full = respond(200, answer);
        await postLend(
            [{ id: 1, version: 3 }],
            {
                borrowerLocationId: 5,
                contact: 'Herr Maier',
                dueBackOn: '2026-12-01',
                comment: 'Übung',
            },
            full.fetch,
        );
        expect(full.requests[0]?.url).toMatch(/\/api\/v1\/loans$/);
        expect(full.requests[0]?.body).toEqual({
            devices: [{ id: 1, version: 3 }],
            borrower_location_id: 5,
            contact: 'Herr Maier',
            due_back_on: '2026-12-01',
            comment: 'Übung',
        });
        const minimal = respond(200, answer);
        await postLend([{ id: 1, version: 3 }], { borrowerLocationId: 5 }, minimal.fetch);
        expect(minimal.requests[0]?.body).toEqual({
            devices: [{ id: 1, version: 3 }],
            borrower_location_id: 5,
        });
    });
});

describe('Verleih: Texte und Gruppen', () => {
    it('Datum und Frist', () => {
        expect(germanDate('2026-11-09')).toBe('09.11.2026');
        expect(germanDate('kaputt')).toBe('kaputt');
        expect(dueText({ dueBackOn: null, overdue: false, daysOverdue: 0 })).toBe('keine Frist');
        expect(dueText({ dueBackOn: '2026-12-01', overdue: false, daysOverdue: 0 })).toBe(
            'Rückgabe bis 01.12.2026',
        );
        expect(dueText({ dueBackOn: '2026-11-08', overdue: true, daysOverdue: 1 })).toBe(
            'seit 1 Tag überfällig (bis 08.11.2026)',
        );
        expect(dueText({ dueBackOn: '2026-11-01', overdue: true, daysOverdue: 8 })).toBe(
            'seit 8 Tagen überfällig (bis 01.11.2026)',
        );
    });

    it('gruppiert nach Leihnehmer in der Reihenfolge des Servers', () => {
        const groups = groupLoans(parseLoans(STATE)?.loans ?? []);
        expect(
            groups.map((group) => [group.borrower.name, group.loans.length, group.overdue]),
        ).toEqual([
            ['FF Nachbar', 2, 1],
            ['Schule', 1, 0],
        ]);
    });

    it('Zusammenfassung und Kachel', () => {
        expect(loansSummary({ open: 0, overdue: 0 })).toBe('Zurzeit ist nichts verliehen.');
        expect(loansSummary({ open: 7, overdue: 0 })).toBe('7 verliehen');
        expect(loansSummary({ open: 7, overdue: 2 })).toBe('7 verliehen, davon 2 überfällig');
        expect(loansTile({ open: 7, overdue: 2 })).toEqual({
            value: '7',
            detail: 'davon 2 überfällig',
        });
        expect(loansTile({ open: 0, overdue: 0 }).detail).toBe('Nichts verliehen');
    });

    it('Prüfung des Fensters', () => {
        const one = [{ id: 1, version: 1, identifier: 'M1', typeName: 'Maske' }];
        expect(lendProblem([], '5', '')).toContain('mindestens eine');
        expect(lendProblem(one, '', '')).toContain('Leihnehmer');
        expect(lendProblem(one, '5', 'x'.repeat(101))).toContain('100 Zeichen');
        expect(lendProblem(one, '5', 'Herr Maier')).toBeNull();
    });
});
