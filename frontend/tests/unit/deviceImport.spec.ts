import { describe, expect, it } from 'vitest';
import {
    downloadImportTemplate,
    downloadImportValues,
    parseImportPreview,
    postImport,
    postImportPreview,
    type ImportRow,
} from '../../src/api/deviceImport';
import {
    changeLines,
    defaultFilter,
    encodingLabel,
    fileSizeProblem,
    filterCount,
    importCountsText,
    matchesFilter,
    MAX_IMPORT_FILE_BYTES,
    toBase64,
} from '../../src/data/deviceImport';
import { respond } from './support/fetch';

const TOKEN = 'a'.repeat(64);
const SUMMARY = { rows: 3, new: 1, changed: 1, unchanged: 0, skipped: 0, errors: 1, warnings: 1 };

/** Antwort von POST devices/import/preview wie DeviceImportJson::preview. */
function previewJson(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        token: null,
        encoding: 'utf-8',
        delimiter: ';',
        delimiter_label: 'Semikolon',
        columns: ['Kennung', 'Gerätetyp', 'Standort'],
        summary: SUMMARY,
        unknown: { device_types: [], locations: ['HLF20'] },
        suggestions: {
            device_types: [],
            locations: [{ value: 'HLF20', suggestions: ['HLF 20'] }],
        },
        rows: [
            {
                line: 2,
                identifier: 'M1',
                result: 'new',
                result_label: 'Neu',
                device: null,
                changes: [
                    { field: 'identifier', label: 'Kennung', old: null, new: 'M1' },
                    { field: 'location', label: 'Standort', old: null, new: 'HLF 20 / G1' },
                    { field: 'note', label: 'Notiz', old: null, new: null },
                ],
                messages: [],
                warnings: ['Die Kennung „M1“ …'],
            },
            {
                line: 3,
                identifier: 'M2',
                result: 'changed',
                result_label: 'Änderungen',
                device: { id: 7, version: 3 },
                changes: [
                    { field: 'status', label: 'Status', old: 'einsatzbereit', new: 'defekt' },
                ],
                messages: [],
                warnings: [],
            },
            {
                line: 4,
                identifier: '',
                result: 'error',
                result_label: 'Fehler',
                device: null,
                changes: [],
                messages: ['Der Standort „HLF20“ ist nicht bekannt. Meinten Sie „HLF 20“?'],
                warnings: [],
            },
        ],
        ...overrides,
    };
}

describe('parseImportPreview', () => {
    it('liest alle Felder', () => {
        const preview = parseImportPreview(previewJson({ token: TOKEN }));
        expect(preview).toMatchObject({
            token: TOKEN,
            encoding: 'utf-8',
            delimiterLabel: 'Semikolon',
            columns: ['Kennung', 'Gerätetyp', 'Standort'],
            summary: SUMMARY,
            unknown: { deviceTypes: [], locations: ['HLF20'] },
            suggestions: {
                deviceTypes: [],
                locations: [{ value: 'HLF20', suggestions: ['HLF 20'] }],
            },
        });
        expect(preview?.rows.map((row) => [row.line, row.result, row.deviceId])).toEqual([
            [2, 'new', null],
            [3, 'changed', 7],
            [4, 'error', null],
        ]);
    });

    it('ohne Feld suggestions (älterer Server): keine Vorschläge', () => {
        const json = previewJson();
        delete json['suggestions'];
        expect(parseImportPreview(json)?.suggestions).toEqual({ deviceTypes: [], locations: [] });
    });

    it.each([
        ['Kennzeichen falsch', { token: 'abc' }],
        ['Zusammenfassung fehlt', { summary: null }],
        ['unbekanntes Ergebnis', { rows: [{ ...rowJson(), result: 'gone' }] }],
        ['Meldungen keine Texte', { rows: [{ ...rowJson(), messages: [1] }] }],
        ['Änderung ohne Feld', { rows: [{ ...rowJson(), changes: [{ label: 'X' }] }] }],
        ['Vorschläge falsch', { suggestions: { device_types: [{ value: 'X' }], locations: [] } }],
        ['unbekannt fehlt', { unknown: null }],
    ])('lehnt ab: %s', (_name, overrides) => {
        expect(parseImportPreview(previewJson(overrides))).toBeNull();
    });
});

/** Zeile `index` der geparsten Vorschau. */
function rowAt(index: number): ImportRow {
    const row = parseImportPreview(previewJson())?.rows[index];
    if (row === undefined) {
        throw new Error(`Zeile ${String(index)} fehlt`);
    }
    return row;
}

function rowJson(): Record<string, unknown> {
    return (previewJson()['rows'] as Record<string, unknown>[])[1] ?? {};
}

describe('Aufrufe', () => {
    it('POST devices/import/preview mit der Datei', async () => {
        const { fetch, requests } = respond(200, previewJson());
        const result = await postImportPreview('QUJD', fetch);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/devices\/import\/preview$/);
        expect(requests[0]?.method).toBe('POST');
        expect(requests[0]?.body).toEqual({ file: 'QUJD' });
        expect(result.ok).toBe(true);
    });

    it('POST devices/import mit Kennzeichen und Kommentar, Antwort die Zusammenfassung', async () => {
        const { fetch, requests } = respond(200, { summary: { ...SUMMARY, errors: 0 } });
        const result = await postImport({ file: 'QUJD', token: TOKEN, comment: 'Inventur' }, fetch);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/devices\/import$/);
        expect(requests[0]?.body).toEqual({ file: 'QUJD', token: TOKEN, comment: 'Inventur' });
        expect(result).toMatchObject({ ok: true, data: { new: 1, changed: 1 } });
    });

    it('ohne Kommentar kein Feld comment', async () => {
        const { fetch, requests } = respond(200, { summary: SUMMARY });
        await postImport({ file: 'QUJD', token: TOKEN }, fetch);
        expect(requests[0]?.body).toEqual({ file: 'QUJD', token: TOKEN });
    });

    it('409 import_outdated als Fehler mit details', async () => {
        const { fetch } = respond(409, {
            error: 'import_outdated',
            message: 'Vorschau neu erstellen.',
            details: { summary: SUMMARY },
        });
        const result = await postImport({ file: 'QUJD', token: TOKEN }, fetch);
        expect(result).toMatchObject({
            ok: false,
            error: { code: 'import_outdated', status: 409 },
        });
    });

    it('Vorlage und gültige Werte als Download', async () => {
        const csv = { 'Content-Type': 'text/csv; charset=utf-8' };
        const template = respond(200, 'Kennung\r\n', {
            ...csv,
            'Content-Disposition': 'attachment; filename="geraete-vorlage-test.csv"',
        });
        const values = respond(200, 'Spalte\r\n', csv);
        const first = await downloadImportTemplate(template.fetch);
        const second = await downloadImportValues(values.fetch);
        expect(template.requests[0]?.url).toMatch(/\/api\/v1\/devices\/import\/template$/);
        expect(values.requests[0]?.url).toMatch(/\/api\/v1\/devices\/import\/values$/);
        expect(first.ok && first.data.fileName).toBe('geraete-vorlage-test.csv');
        expect(second.ok && second.data.fileName).toBe('gueltige-werte.csv');
    });
});

describe('Hilfen der Seite', () => {
    it('toBase64 auch für große Dateien und alle Bytes', () => {
        const bytes = new Uint8Array(100_000).map((_, i) => i % 256);
        const encoded = toBase64(bytes);
        expect(Uint8Array.from(atob(encoded), (c) => c.charCodeAt(0))).toEqual(bytes);
        expect(toBase64(new TextEncoder().encode('Gerätetyp;M1'))).toBe(
            Buffer.from('Gerätetyp;M1').toString('base64'),
        );
        expect(toBase64(new Uint8Array())).toBe('');
    });

    it('fileSizeProblem: leer und über 2 MiB', () => {
        expect(fileSizeProblem(0)).toBe('Die Datei ist leer.');
        expect(fileSizeProblem(MAX_IMPORT_FILE_BYTES)).toBeNull();
        expect(fileSizeProblem(MAX_IMPORT_FILE_BYTES + 1)).toBe(
            'Die Datei ist zu groß (2 MiB, höchstens 2 MiB). Bitte in mehrere Dateien aufteilen.',
        );
        expect(fileSizeProblem(3_200_000)).toContain('(3,1 MiB,');
    });

    it('Filter und Anzahl', () => {
        const rows = parseImportPreview(previewJson())?.rows ?? [];
        const lines = (filter: Parameters<typeof matchesFilter>[1]): number[] =>
            rows.filter((row) => matchesFilter(row, filter)).map((row) => row.line);
        expect(lines('all')).toEqual([2, 3, 4]);
        expect(lines('error')).toEqual([4]);
        expect(lines('warning')).toEqual([2]);
        expect(lines('new')).toEqual([2]);
        expect(lines('changed')).toEqual([3]);
        expect(filterCount(SUMMARY, 'all')).toBe(3);
        expect(filterCount(SUMMARY, 'warning')).toBe(1);
        expect(filterCount(SUMMARY, 'skipped')).toBe(0);
    });

    it('defaultFilter: Fehler, sonst Warnungen, sonst alle', () => {
        expect(defaultFilter(SUMMARY)).toBe('error');
        expect(defaultFilter({ ...SUMMARY, errors: 0 })).toBe('warning');
        expect(defaultFilter({ ...SUMMARY, errors: 0, warnings: 0 })).toBe('all');
    });

    it('importCountsText', () => {
        expect(importCountsText({ new: 2, changed: 1 })).toBe('2 neu, 1 geändert');
        expect(importCountsText({ new: 0, changed: 4 })).toBe('4 geändert');
        expect(importCountsText({ new: 1, changed: 0 })).toBe('1 neu');
        expect(importCountsText({ new: 0, changed: 0 })).toBe('keine Änderung');
    });

    it('encodingLabel', () => {
        expect(encodingLabel('utf-8')).toBe('UTF-8');
        expect(encodingLabel('windows-1252')).toBe('Windows-1252 (in UTF-8 umgewandelt)');
    });

    it('changeLines: neu ohne Kennung, sonst alt → neu, leer = „leer“', () => {
        expect(changeLines(rowAt(0))).toEqual(['Standort: HLF 20 / G1', 'Notiz: leer']);
        expect(changeLines(rowAt(1))).toEqual(['Status: einsatzbereit → defekt']);
        expect(
            changeLines({
                ...rowAt(1),
                changes: [{ field: 'identifier', label: 'Kennung', old: 'm2', new: 'M2' }],
            }),
        ).toEqual(['Kennung: m2 → M2']);
    });
});
