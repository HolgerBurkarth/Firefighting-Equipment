import type { ImportChange, ImportRow, ImportSummary } from '../api/deviceImport';

/**
 * Hilfen der Import-Seite (`geraete/import`): Datei als Base64, Filter und Texte der Vorschau. Die
 * Prüfung selbst rechnet der Server (CLAUDE.md, "CSV-Import (API)").
 */

/** Größte Datei (DeviceImportFile::MAX_BYTES); größere lehnt die App ab, ohne sie zu senden. */
export const MAX_IMPORT_FILE_BYTES = 2_097_152;

/** So viele Zeilen der Vorschau auf einmal („Weitere anzeigen“). */
export const IMPORT_ROWS_STEP = 100;

/** Base64 wie `base64_decode(…, true)` in PHP es erwartet (in Blöcken, große Dateien). */
export function toBase64(bytes: Uint8Array): string {
    const CHUNK = 0x8000;
    let binary = '';
    for (let offset = 0; offset < bytes.length; offset += CHUNK) {
        binary += String.fromCharCode(...bytes.subarray(offset, offset + CHUNK));
    }
    return btoa(binary);
}

/** Meldung, wenn die Datei leer oder zu groß ist, sonst null. */
export function fileSizeProblem(size: number): string | null {
    if (size === 0) {
        return 'Die Datei ist leer.';
    }
    if (size > MAX_IMPORT_FILE_BYTES) {
        const mib = (size / 1_048_576).toLocaleString('de-DE', { maximumFractionDigits: 1 });
        return `Die Datei ist zu groß (${mib} MiB, höchstens 2 MiB). Bitte in mehrere Dateien aufteilen.`;
    }
    return null;
}

export type ImportRowFilter =
    'all' | 'error' | 'warning' | 'new' | 'changed' | 'unchanged' | 'skipped';

/** Filter der Vorschau in dieser Reihenfolge (Knöpfe mit Anzahl). */
export const IMPORT_FILTERS: readonly {
    readonly filter: ImportRowFilter;
    readonly label: string;
}[] = [
    { filter: 'all', label: 'Alle' },
    { filter: 'error', label: 'Fehler' },
    { filter: 'warning', label: 'Mit Warnung' },
    { filter: 'new', label: 'Neu' },
    { filter: 'changed', label: 'Geändert' },
    { filter: 'unchanged', label: 'Unverändert' },
    { filter: 'skipped', label: 'Übersprungen' },
];

export function matchesFilter(row: ImportRow, filter: ImportRowFilter): boolean {
    switch (filter) {
        case 'all':
            return true;
        case 'warning':
            return row.warnings.length > 0;
        default:
            return row.result === filter;
    }
}

/** Anzahl Zeilen je Filter (aus der Zusammenfassung des Servers). */
export function filterCount(summary: ImportSummary, filter: ImportRowFilter): number {
    const counts: Readonly<Record<ImportRowFilter, number>> = {
        all: summary.rows,
        error: summary.errors,
        warning: summary.warnings,
        new: summary.new,
        changed: summary.changed,
        unchanged: summary.unchanged,
        skipped: summary.skipped,
    };
    return counts[filter];
}

/** Erst die Fehler, sonst die Zeilen mit Warnung, sonst alle. */
export function defaultFilter(summary: ImportSummary): ImportRowFilter {
    if (summary.errors > 0) {
        return 'error';
    }
    return summary.warnings > 0 ? 'warning' : 'all';
}

/** „2 neu, 1 geändert“ (nur was der Import schreibt). */
export function importCountsText(summary: Pick<ImportSummary, 'new' | 'changed'>): string {
    const parts: string[] = [];
    if (summary.new > 0) {
        parts.push(`${String(summary.new)} neu`);
    }
    if (summary.changed > 0) {
        parts.push(`${String(summary.changed)} geändert`);
    }
    return parts.length === 0 ? 'keine Änderung' : parts.join(', ');
}

export function encodingLabel(encoding: string): string {
    return encoding === 'windows-1252' ? 'Windows-1252 (in UTF-8 umgewandelt)' : 'UTF-8';
}

/**
 * Zeilen der Änderungen: bei neuen Geräten „Standort: HLF 20 / G1“ (ohne die Kennung, sie steht im
 * Kopf), sonst „Standort: HLF 20 / G1 → Lager“; leer = „leer“.
 */
export function changeLines(row: ImportRow): string[] {
    const shown = (value: string | null): string => value ?? 'leer';
    return row.changes
        .filter((change: ImportChange) => row.result !== 'new' || change.field !== 'identifier')
        .map((change) =>
            row.result === 'new'
                ? `${change.label}: ${shown(change.new)}`
                : `${change.label}: ${shown(change.old)} → ${shown(change.new)}`,
        );
}
