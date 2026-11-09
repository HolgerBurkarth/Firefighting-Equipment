import { describe, expect, it } from 'vitest';
import { fetchStock, parseStock, postStockRules } from '../../src/api/stock';
import {
    draftsChanged,
    draftsOf,
    ruleText,
    stockInputs,
    stockProblem,
    stockSummary,
    typesText,
} from '../../src/data/stock';
import { respond } from './support/fetch';

const RULE = {
    id: 5,
    min_count: 3,
    types: [
        { id: 1, name: 'Atemschutzmaske' },
        { id: 2, name: 'Funkgerät' },
    ],
    have: 2,
    missing: 1,
};

const STATE = {
    locations: [
        {
            location: { id: 10, name: 'G1', path: 'HLF 20 / G1', level: 2 },
            rules: [RULE],
            out_of_service: true,
        },
    ],
    summary: { locations: 1, out_of_service: 1 },
};

describe('Sollbestand: API', () => {
    it('liest Regeln mit Soll, Ist und Fehlendem', () => {
        const state = parseStock(STATE);
        expect(state?.outOfService).toBe(1);
        expect(state?.entries[0]?.location).toEqual({
            id: 10,
            name: 'G1',
            path: 'HLF 20 / G1',
            level: 2,
        });
        expect(state?.entries[0]?.rules[0]).toMatchObject({ minCount: 3, have: 2, missing: 1 });
        expect(state?.entries[0]?.outOfService).toBe(true);
    });

    it.each([
        ['kein Objekt', 'x'],
        [
            'Standort unbrauchbar',
            { ...STATE, locations: [{ ...STATE.locations[0], location: { id: 1 } }] },
        ],
        [
            'Regel unbrauchbar',
            { ...STATE, locations: [{ ...STATE.locations[0], rules: [{ id: 1 }] }] },
        ],
        ['Zusammenfassung fehlt', { ...STATE, summary: null }],
    ])('lehnt ab: %s', (_label, json) => {
        expect(parseStock(json)).toBeNull();
    });

    it('GET stock und POST locations/{id}/stock-rules', async () => {
        const get = respond(200, STATE);
        expect((await fetchStock(get.fetch)).ok).toBe(true);
        expect(get.requests[0]?.url).toMatch(/\/api\/v1\/stock$/);

        const post = respond(200, { stock: { rules: [RULE], out_of_service: true } });
        const saved = await postStockRules(
            10,
            [{ minCount: 3, deviceTypeIds: [1, 2] }],
            post.fetch,
        );
        expect(post.requests[0]?.url).toMatch(/\/api\/v1\/locations\/10\/stock-rules$/);
        expect(post.requests[0]?.body).toEqual({
            rules: [{ min_count: 3, device_type_ids: [1, 2] }],
        });
        expect(saved.ok && saved.data.stock?.outOfService).toBe(true);

        const none = respond(200, { stock: null });
        const removed = await postStockRules(10, [], none.fetch);
        expect(none.requests[0]?.body).toEqual({ rules: [] });
        expect(removed.ok && removed.data.stock).toBeNull();
    });
});

describe('Sollbestand: Texte und Prüfung', () => {
    it('Typen mit „oder“', () => {
        expect(typesText([])).toBe('');
        expect(typesText(['A'])).toBe('A');
        expect(typesText(['A', 'B'])).toBe('A oder B');
        expect(typesText(['A', 'B', 'C'])).toBe('A, B oder C');
    });

    it('Regel und Zusammenfassung', () => {
        const rule = parseStock(STATE)?.entries[0]?.rules[0];
        if (rule === undefined) {
            throw new Error('Regel');
        }
        expect(ruleText(rule)).toBe(
            'mindestens 3 Atemschutzmaske oder Funkgerät: 2 vorhanden, 1 fehlt',
        );
        expect(ruleText({ ...rule, have: 1, missing: 2 })).toContain('2 fehlen');
        expect(ruleText({ ...rule, have: 3, missing: 0 })).toBe(
            'mindestens 3 Atemschutzmaske oder Funkgerät: 3 vorhanden',
        );
        const entries = parseStock(STATE)?.entries ?? [];
        expect(stockSummary([])).toBe('Noch kein Sollbestand festgelegt.');
        expect(stockSummary(entries)).toBe('1 Standort mit Sollbestand, 1 außer Betrieb');
        expect(stockSummary(entries.map((entry) => ({ ...entry, outOfService: false })))).toBe(
            '1 Standort mit Sollbestand, alle vollständig',
        );
    });

    it('Entwürfe: Prüfung, Senden, Änderung', () => {
        const entry = parseStock(STATE)?.entries[0] ?? null;
        const drafts = draftsOf(entry);
        expect(drafts).toEqual([{ count: '3', typeIds: [1, 2] }]);
        expect(draftsChanged(entry, drafts)).toBe(false);
        expect(draftsChanged(entry, [{ count: '4', typeIds: [1, 2] }])).toBe(true);
        expect(draftsChanged(null, [])).toBe(false);
        expect(stockProblem(drafts)).toBeNull();
        expect(stockProblem([{ count: '0', typeIds: [1] }])).toContain('Regel 1');
        expect(stockProblem([{ count: '100', typeIds: [1] }])).toContain('1 bis 99');
        expect(stockProblem([{ count: 'x', typeIds: [1] }])).toContain('Soll-Anzahl');
        expect(stockProblem([{ count: '2', typeIds: [] }])).toContain('Ausrüstungstyp');
        expect(
            stockProblem(Array.from({ length: 21 }, () => ({ count: '1', typeIds: [1] }))),
        ).toContain('20');
        expect(stockInputs([{ count: ' 2 ', typeIds: [3] }])).toEqual([
            { minCount: 2, deviceTypeIds: [3] },
        ]);
    });
});
