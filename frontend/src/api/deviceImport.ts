import { apiDownload, apiRequest, isRecord, type ApiResult, type DownloadedFile } from './client';

/**
 * CSV-Import der Geräte (Admin; CLAUDE.md, "CSV-Import (API)"): Vorschau, Übernehmen und die
 * Hilfen zum Erfassen (Vorlage, „Gültige Werte“).
 */

export type ImportRowResult = 'new' | 'changed' | 'unchanged' | 'skipped' | 'error';

const RESULTS: readonly ImportRowResult[] = ['new', 'changed', 'unchanged', 'skipped', 'error'];

/** Eine Änderung in der Schreibweise der Datei (Namen, Pfade, deutsche Status, TT.MM.JJJJ). */
export interface ImportChange {
    readonly field: string;
    readonly label: string;
    /** null = leer (bei neuen Geräten immer). */
    readonly old: string | null;
    readonly new: string | null;
}

export interface ImportRow {
    /** Zeilennummer wie in Excel (Kopfzeile = 1). */
    readonly line: number;
    readonly identifier: string;
    readonly result: ImportRowResult;
    readonly resultLabel: string;
    /** Bestehendes Gerät, sonst null. */
    readonly deviceId: number | null;
    readonly changes: readonly ImportChange[];
    /** Fehler bzw. Grund fürs Überspringen. */
    readonly messages: readonly string[];
    readonly warnings: readonly string[];
}

export interface ImportSummary {
    readonly rows: number;
    readonly new: number;
    readonly changed: number;
    readonly unchanged: number;
    readonly skipped: number;
    readonly errors: number;
    /** Zeilen mit mindestens einer Warnung. */
    readonly warnings: number;
}

/** Unbekannter Wert mit ähnlichen gültigen Werten („Meinten Sie …?“). */
export interface ImportSuggestion {
    readonly value: string;
    readonly suggestions: readonly string[];
}

export interface ImportUnknown<T> {
    readonly deviceTypes: readonly T[];
    readonly locations: readonly T[];
}

export interface ImportPreview {
    /** Kennzeichen zum Übernehmen; null, solange die Datei Fehler enthält. */
    readonly token: string | null;
    /** `utf-8` oder `windows-1252`. */
    readonly encoding: string;
    readonly delimiterLabel: string;
    readonly columns: readonly string[];
    readonly summary: ImportSummary;
    readonly unknown: ImportUnknown<string>;
    readonly suggestions: ImportUnknown<ImportSuggestion>;
    readonly rows: readonly ImportRow[];
}

function isCount(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}

function stringList(value: unknown): string[] | null {
    return Array.isArray(value) && value.every((entry) => typeof entry === 'string') ? value : null;
}

function nullableText(value: unknown): string | null | undefined {
    return value === null || typeof value === 'string' ? value : undefined;
}

export function parseImportSummary(json: unknown): ImportSummary | null {
    if (!isRecord(json)) {
        return null;
    }
    const { rows, changed, unchanged, skipped, errors, warnings } = json;
    const created = json['new'];
    if (
        !isCount(rows) ||
        !isCount(created) ||
        !isCount(changed) ||
        !isCount(unchanged) ||
        !isCount(skipped) ||
        !isCount(errors) ||
        !isCount(warnings)
    ) {
        return null;
    }
    return { rows, new: created, changed, unchanged, skipped, errors, warnings };
}

function parseChange(json: unknown): ImportChange | null {
    if (!isRecord(json) || typeof json['field'] !== 'string' || typeof json['label'] !== 'string') {
        return null;
    }
    const old = nullableText(json['old']);
    const changed = nullableText(json['new']);
    if (old === undefined || changed === undefined) {
        return null;
    }
    return { field: json['field'], label: json['label'], old, new: changed };
}

function parseRow(json: unknown): ImportRow | null {
    if (!isRecord(json)) {
        return null;
    }
    const { line, identifier, result, device } = json;
    const messages = stringList(json['messages']);
    const warnings = stringList(json['warnings']);
    const changes = Array.isArray(json['changes']) ? json['changes'].map(parseChange) : null;
    if (
        typeof line !== 'number' ||
        !Number.isSafeInteger(line) ||
        typeof identifier !== 'string' ||
        typeof result !== 'string' ||
        !(RESULTS as readonly string[]).includes(result) ||
        typeof json['result_label'] !== 'string' ||
        messages === null ||
        warnings === null ||
        changes === null ||
        changes.includes(null)
    ) {
        return null;
    }
    let deviceId: number | null = null;
    if (device !== null) {
        if (!isRecord(device) || typeof device['id'] !== 'number') {
            return null;
        }
        deviceId = device['id'];
    }
    return {
        line,
        identifier,
        result: result as ImportRowResult,
        resultLabel: json['result_label'],
        deviceId,
        changes: changes as ImportChange[],
        messages,
        warnings,
    };
}

function parseSuggestions(json: unknown): ImportSuggestion[] | null {
    if (!Array.isArray(json)) {
        return null;
    }
    const parsed: ImportSuggestion[] = [];
    for (const entry of json) {
        const suggestions = isRecord(entry) ? stringList(entry['suggestions']) : null;
        if (!isRecord(entry) || typeof entry['value'] !== 'string' || suggestions === null) {
            return null;
        }
        parsed.push({ value: entry['value'], suggestions });
    }
    return parsed;
}

export function parseImportPreview(json: unknown): ImportPreview | null {
    if (!isRecord(json)) {
        return null;
    }
    const { token, encoding, unknown } = json;
    const summary = parseImportSummary(json['summary']);
    const columns = stringList(json['columns']);
    const rows = Array.isArray(json['rows']) ? json['rows'].map(parseRow) : null;
    const unknownTypes = isRecord(unknown) ? stringList(unknown['device_types']) : null;
    const unknownLocations = isRecord(unknown) ? stringList(unknown['locations']) : null;
    // Ältere Server ohne Vorschläge: keine.
    const suggestions: Record<string, unknown> = isRecord(json['suggestions'])
        ? json['suggestions']
        : {};
    const typeSuggestions = parseSuggestions(suggestions['device_types'] ?? []);
    const locationSuggestions = parseSuggestions(suggestions['locations'] ?? []);
    if (
        (token !== null && (typeof token !== 'string' || !/^[0-9a-f]{64}$/.test(token))) ||
        typeof encoding !== 'string' ||
        typeof json['delimiter_label'] !== 'string' ||
        summary === null ||
        columns === null ||
        rows === null ||
        rows.includes(null) ||
        unknownTypes === null ||
        unknownLocations === null ||
        typeSuggestions === null ||
        locationSuggestions === null
    ) {
        return null;
    }
    return {
        token,
        encoding,
        delimiterLabel: json['delimiter_label'],
        columns,
        summary,
        unknown: { deviceTypes: unknownTypes, locations: unknownLocations },
        suggestions: { deviceTypes: typeSuggestions, locations: locationSuggestions },
        rows: rows as ImportRow[],
    };
}

/** POST devices/import/preview: prüft die Datei (Base64), speichert nichts. */
export function postImportPreview(
    file: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<ImportPreview>> {
    return apiRequest('devices/import/preview', parseImportPreview, {
        method: 'POST',
        body: { file },
        ...(fetchFn && { fetchFn }),
    });
}

export interface ImportRequest {
    /** Dieselbe Datei wie in der Vorschau (Base64). */
    readonly file: string;
    readonly token: string;
    readonly comment?: string;
}

/**
 * POST devices/import: übernimmt alles oder nichts. 409 import_outdated: seit der Vorschau geändert
 * (`details.summary` = neue Prüfung).
 */
export function postImport(
    request: ImportRequest,
    fetchFn?: typeof fetch,
): Promise<ApiResult<ImportSummary>> {
    return apiRequest(
        'devices/import',
        (json) => (isRecord(json) ? parseImportSummary(json['summary']) : null),
        {
            method: 'POST',
            body: {
                file: request.file,
                token: request.token,
                ...(request.comment !== undefined && { comment: request.comment }),
            },
            ...(fetchFn && { fetchFn }),
        },
    );
}

/** GET devices/import/template: Vorlage mit den Spalten und zwei Beispielzeilen. */
export function downloadImportTemplate(fetchFn?: typeof fetch): Promise<ApiResult<DownloadedFile>> {
    return apiDownload('devices/import/template', 'geraete-vorlage.csv', fetchFn);
}

/** GET devices/import/values: aktive Gerätetypen und Standorte, Status, Formate. */
export function downloadImportValues(fetchFn?: typeof fetch): Promise<ApiResult<DownloadedFile>> {
    return apiDownload('devices/import/values', 'gueltige-werte.csv', fetchFn);
}
