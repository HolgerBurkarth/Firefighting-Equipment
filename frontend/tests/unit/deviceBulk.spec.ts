import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import type { ApiError, ApiResult } from '../../src/api/client';
import {
    BULK_MAX_DEVICES,
    parseBulkResponse,
    postBulkInspectionDue,
    postBulkState,
    type BulkItemResult,
    type BulkResponse,
} from '../../src/api/deviceBulk';
import { AdminDeviceList } from '../../src/data/adminDeviceList';
import {
    bulkCommentProblem,
    bulkCounts,
    bulkErrorText,
    bulkResultText,
    bulkStateProblem,
    chunks,
    runInBlocks,
    type BulkRun,
} from '../../src/data/deviceBulk';
import { DEFAULT_DEVICE_FILTER } from '../../src/data/deviceList';
import { respond } from './support/fetch';
import { detail, deviceJson, devices, pagedSource } from './support/devices';

const NETWORK_ERROR: ApiError = {
    code: 'network',
    message: null,
    status: null,
    retryAfterSeconds: null,
    details: null,
};
const NETWORK: ApiResult<BulkResponse> = { ok: false, error: NETWORK_ERROR };

function fullJson(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return deviceJson(id, { procured_on: null, note: null, ...overrides });
}

function item(id: number, outcome: BulkItemResult['outcome'], code?: string): BulkItemResult {
    return {
        id,
        outcome,
        replayed: false,
        device: detail(id),
        error: code === undefined ? null : { code, message: `Fehler ${code}`, details: null },
    };
}

/** Antwort des simulierten Servers: jedes Gerät übernommen. */
function applied(block: readonly { id: number }[]): Promise<ApiResult<BulkResponse>> {
    return Promise.resolve({
        ok: true,
        status: 200,
        data: {
            results: block.map((entry) => item(entry.id, 'applied')),
            summary: { total: block.length, applied: block.length, unchanged: 0, errors: 0 },
        },
    });
}

describe('Grenze der Anfrage', () => {
    it('gleich DeviceBulkHandler::MAX_DEVICES', () => {
        const php = readFileSync(
            resolve(process.cwd(), '../src/Api/V1/Devices/DeviceBulkHandler.php'),
            'utf8',
        );
        expect(/const int MAX_DEVICES = (\d+);/.exec(php)?.[1]).toBe(String(BULK_MAX_DEVICES));
    });
});

describe('API', () => {
    it('POST devices/bulk/inspection-due mit Version und Datum je Gerät', async () => {
        const { fetch, requests } = respond(200, {
            results: [
                {
                    id: 5,
                    outcome: 'applied',
                    replayed: false,
                    device: fullJson(5, { version: 2 }),
                    error: null,
                },
                {
                    id: 6,
                    outcome: 'error',
                    replayed: false,
                    device: fullJson(6, { version: 4 }),
                    error: {
                        error: 'version_conflict',
                        message: 'Inzwischen geändert.',
                        details: { device: fullJson(6) },
                    },
                },
                {
                    id: 7,
                    outcome: 'error',
                    replayed: false,
                    device: null,
                    error: { error: 'not_found', message: 'Nicht da.' },
                },
            ],
            summary: { total: 3, applied: 1, unchanged: 0, errors: 2 },
        });
        const result = await postBulkInspectionDue(
            [
                { id: 5, version: 1, nextInspectionDue: '2027-03-01' },
                { id: 6, version: 3, nextInspectionDue: null },
            ],
            'Prüfung',
            fetch,
        );
        expect(requests[0]?.url).toMatch(/\/api\/v1\/devices\/bulk\/inspection-due$/);
        expect(requests[0]?.method).toBe('POST');
        expect(requests[0]?.body).toEqual({
            devices: [
                { id: 5, version: 1, next_inspection_due: '2027-03-01' },
                { id: 6, version: 3, next_inspection_due: null },
            ],
            comment: 'Prüfung',
        });
        expect(result.ok).toBe(true);
        if (!result.ok) {
            return;
        }
        expect(result.data.summary).toEqual({ total: 3, applied: 1, unchanged: 0, errors: 2 });
        expect(
            result.data.results.map((r) => [r.id, r.outcome, r.device?.version ?? null]),
        ).toEqual([
            [5, 'applied', 2],
            [6, 'error', 4],
            [7, 'error', null],
        ]);
        expect(result.data.results[1]?.error?.code).toBe('version_conflict');
        expect(result.data.results[2]?.error).toEqual({
            code: 'not_found',
            message: 'Nicht da.',
            details: null,
        });
    });

    it('POST devices/bulk/state nur mit den gewählten Zielen und client_ref je Gerät', async () => {
        const { fetch, requests } = respond(200, {
            results: [
                { id: 5, outcome: 'unchanged', replayed: true, device: fullJson(5), error: null },
            ],
            summary: { total: 1, applied: 0, unchanged: 1, errors: 0 },
        });
        const result = await postBulkState(
            [
                { id: 5, version: 1, status: 'defective', clientRef: 'a' },
                { id: 6, version: 2, locationId: 11, clientRef: 'b' },
            ],
            undefined,
            fetch,
        );
        expect(requests[0]?.url).toMatch(/\/api\/v1\/devices\/bulk\/state$/);
        expect(requests[0]?.body).toEqual({
            devices: [
                { id: 5, version: 1, status: 'defective', client_ref: 'a' },
                { id: 6, version: 2, location_id: 11, client_ref: 'b' },
            ],
        });
        expect(result.ok && result.data.results[0]?.replayed).toBe(true);
    });

    it('lehnt unerwartete Antworten ab', () => {
        const ok = { id: 5, outcome: 'applied', replayed: false, device: fullJson(5), error: null };
        const summary = { total: 1, applied: 1, unchanged: 0, errors: 0 };
        expect(parseBulkResponse({ results: [ok], summary })).not.toBeNull();
        expect(parseBulkResponse({ results: [{ ...ok, outcome: 'done' }], summary })).toBeNull();
        expect(parseBulkResponse({ results: [{ ...ok, device: null }], summary })).toBeNull();
        expect(parseBulkResponse({ results: [{ ...ok, outcome: 'error' }], summary })).toBeNull();
        expect(
            parseBulkResponse({
                results: [{ ...ok, error: { error: 'x', message: 'y' } }],
                summary,
            }),
        ).toBeNull();
        expect(parseBulkResponse({ results: [ok], summary: { ...summary, total: -1 } })).toBeNull();
        expect(parseBulkResponse({ results: [ok] })).toBeNull();
    });
});

describe('Blöcke', () => {
    it('teilt in Blöcke zu höchstens 50', () => {
        expect(chunks([1, 2, 3, 4, 5], 2)).toEqual([[1, 2], [3, 4], [5]]);
        expect(chunks([])).toEqual([]);
        expect(
            chunks(Array.from({ length: 120 }, (_, i) => i)).map((block) => block.length),
        ).toEqual([50, 50, 20]);
    });

    it('sendet nacheinander und meldet den Fortschritt', async () => {
        const entries = Array.from({ length: 120 }, (_, i) => ({ id: i + 1 }));
        const blocks: number[] = [];
        const progress: string[] = [];
        const run = await runInBlocks(
            entries,
            (block) => {
                blocks.push(block.length);
                return applied(block);
            },
            (done, total) => progress.push(`${String(done)} von ${String(total)}`),
        );
        expect(blocks).toEqual([50, 50, 20]);
        expect(progress).toEqual(['0 von 120', '50 von 120', '100 von 120', '120 von 120']);
        expect(run.results).toHaveLength(120);
        expect([run.error, run.unsent]).toEqual([null, []]);
    });

    it('endet bei einem Fehler der ganzen Anfrage; der Rest bleibt ungesendet', async () => {
        const entries = Array.from({ length: 120 }, (_, i) => ({ id: i + 1 }));
        let call = 0;
        const run = await runInBlocks(entries, (block) =>
            ++call === 2 ? Promise.resolve(NETWORK) : applied(block),
        );
        expect(call).toBe(2);
        expect(run.results).toHaveLength(50);
        expect(run.error?.code).toBe('network');
        expect(run.unsent).toEqual(Array.from({ length: 70 }, (_, i) => i + 51));
    });
});

describe('Ergebnis', () => {
    const run: BulkRun = {
        results: [
            item(1, 'applied'),
            item(2, 'applied'),
            item(3, 'unchanged'),
            item(4, 'error', 'version_conflict'),
        ],
        error: null,
        unsent: [],
    };

    it('zählt und fasst zusammen', () => {
        expect(bulkCounts(run)).toEqual({ applied: 2, unchanged: 1, errors: 1 });
        expect(bulkResultText('inspection-due', run)).toBe(
            'Prüfdatum: 2 Geräte geändert, 1 unverändert, 1 nicht möglich.',
        );
        expect(
            bulkResultText('state', { results: [item(1, 'applied')], error: null, unsent: [] }),
        ).toBe('Umbuchen / Status: 1 Gerät geändert.');
        expect(
            bulkResultText('state', {
                results: [],
                error: NETWORK_ERROR,
                unsent: [1, 2],
            }),
        ).toBe('Umbuchen / Status: 0 Geräte geändert, 2 nicht gesendet.');
    });

    it('Meldungen je Gerät', () => {
        expect(bulkErrorText(item(4, 'error', 'version_conflict'))).toBe(
            'Inzwischen geändert – bitte prüfen und erneut senden.',
        );
        expect(bulkErrorText(item(4, 'error', 'not_found'))).toBe(
            'Dieses Gerät gibt es nicht (mehr).',
        );
        expect(bulkErrorText(item(4, 'error', 'rule_violation'))).toBe('Fehler rule_violation');
    });
});

describe('Fenster „Umbuchen / Status ändern“', () => {
    it('braucht ein Ziel und bei „defekt“ eine Beschreibung', () => {
        expect(bulkStateProblem({ locationId: null, status: null, comment: '' })).toBe(
            'Bitte einen neuen Standort und/oder Status wählen.',
        );
        expect(bulkStateProblem({ locationId: 5, status: null, comment: '' })).toBeNull();
        expect(
            bulkCommentProblem({ locationId: null, status: 'maintenance', comment: '' }),
        ).toBeNull();
        expect(
            bulkCommentProblem({ locationId: null, status: 'defective', comment: ' Riss ' }),
        ).toBe('Bitte beschreiben Sie den Defekt mit mindestens 5 Zeichen.');
        expect(
            bulkCommentProblem({ locationId: null, status: 'defective', comment: 'Ventil' }),
        ).toBeNull();
    });
});

describe('Alle Geräte des Filters', () => {
    it('lädt alle Blöcke zu 200 ohne die angezeigte Liste zu ändern', async () => {
        const source = pagedSource(devices(1, 450));
        const list = new AdminDeviceList((query) => source.fetchPage(query));
        await list.show(DEFAULT_DEVICE_FILTER);
        const result = await list.all();
        expect(result.ok && result.data.map((d) => d.id)).toEqual(
            Array.from({ length: 450 }, (_, i) => i + 1),
        );
        expect(source.calls.slice(1)).toEqual([
            { limit: 200, offset: 0 },
            { limit: 200, offset: 200 },
            { limit: 200, offset: 400 },
        ]);
        expect(list.state.devices).toHaveLength(50);
    });

    it('meldet einen Fehler; ohne Filter leer', async () => {
        const list = new AdminDeviceList(() =>
            Promise.resolve({ ok: false, error: NETWORK_ERROR }),
        );
        expect(await list.all()).toEqual({ ok: true, status: 200, data: [] });
        void list.show(DEFAULT_DEVICE_FILTER);
        const result = await list.all();
        expect(!result.ok && result.error.code).toBe('network');
    });
});
