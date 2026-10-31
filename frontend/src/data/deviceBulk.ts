import type { ApiError, ApiResult } from '../api/client';
import { BULK_MAX_DEVICES, type BulkItemResult, type BulkResponse } from '../api/deviceBulk';
import type { BookableStatus } from '../api/devices';
import { commentLength, MIN_DEFECT_COMMENT_LENGTH } from './booking';

/**
 * Sammelaktionen der Geräteliste (KONZEPT.md "Admin-Konsole – Entscheidungen", 31): größere
 * Auswahlen in Blöcken zu BULK_MAX_DEVICES nacheinander senden, Fortschritt und Ergebnis.
 */

export type BulkAction = 'inspection-due' | 'state';

/** Ergebnis eines Laufs über alle Blöcke. */
export interface BulkRun {
    /** Ergebnisse je Gerät in der Reihenfolge der Auswahl (nur gesendete Blöcke). */
    readonly results: readonly BulkItemResult[];
    /** Fehler einer ganzen Anfrage (z. B. keine Verbindung); danach wird nichts mehr gesendet. */
    readonly error: ApiError | null;
    /** Geräte ohne Ergebnis (der gescheiterte Block und alle danach). */
    readonly unsent: readonly number[];
}

/** Teilt eine Liste in Blöcke zu höchstens `size` Einträgen. */
export function chunks<T>(items: readonly T[], size: number = BULK_MAX_DEVICES): T[][] {
    const blocks: T[][] = [];
    for (let start = 0; start < items.length; start += size) {
        blocks.push(items.slice(start, start + size));
    }
    return blocks;
}

/**
 * Sendet die Einträge in Blöcken nacheinander (der Server bucht jedes Gerät einzeln). Scheitert eine
 * ganze Anfrage, endet der Lauf; offen ist dann, ob dieser Block angekommen ist, aber die Versionen
 * verhindern eine doppelte Wirkung beim erneuten Senden.
 */
export async function runInBlocks<T extends { readonly id: number }>(
    entries: readonly T[],
    send: (block: readonly T[]) => Promise<ApiResult<BulkResponse>>,
    onProgress: (done: number, total: number) => void = () => undefined,
): Promise<BulkRun> {
    const results: BulkItemResult[] = [];
    let done = 0;
    onProgress(0, entries.length);
    for (const block of chunks(entries)) {
        const response = await send(block);
        if (!response.ok) {
            return {
                results,
                error: response.error,
                unsent: entries.slice(done).map((entry) => entry.id),
            };
        }
        results.push(...response.data.results);
        done += block.length;
        onProgress(done, entries.length);
    }
    return { results, error: null, unsent: [] };
}

export interface BulkCounts {
    readonly applied: number;
    readonly unchanged: number;
    readonly errors: number;
}

export function bulkCounts(run: BulkRun): BulkCounts {
    const count = (outcome: BulkItemResult['outcome']): number =>
        run.results.filter((result) => result.outcome === outcome).length;
    return { applied: count('applied'), unchanged: count('unchanged'), errors: count('error') };
}

function devices(count: number): string {
    return count === 1 ? '1 Gerät' : `${String(count)} Geräte`;
}

const ACTION_LABELS: Readonly<Record<BulkAction, string>> = {
    'inspection-due': 'Prüfdatum',
    state: 'Umbuchen / Status',
};

/** Zusammenfassung, z. B. „Prüfdatum: 10 Geräte geändert, 1 unverändert, 1 nicht möglich.“ */
export function bulkResultText(action: BulkAction, run: BulkRun): string {
    const counts = bulkCounts(run);
    const parts = [`${devices(counts.applied)} geändert`];
    if (counts.unchanged > 0) {
        parts.push(`${String(counts.unchanged)} unverändert`);
    }
    if (counts.errors > 0) {
        parts.push(`${String(counts.errors)} nicht möglich`);
    }
    if (run.unsent.length > 0) {
        parts.push(`${String(run.unsent.length)} nicht gesendet`);
    }
    return `${ACTION_LABELS[action]}: ${parts.join(', ')}.`;
}

/** Meldung zu einem Gerät, das nicht geändert werden konnte. */
export function bulkErrorText(result: BulkItemResult): string {
    switch (result.error?.code) {
        case 'version_conflict':
            return 'Inzwischen geändert – bitte prüfen und erneut senden.';
        case 'not_found':
            return 'Dieses Gerät gibt es nicht (mehr).';
        default:
            return result.error?.message ?? 'Nicht möglich.';
    }
}

/** Eingaben im Fenster „Umbuchen / Status ändern“; null = nicht ändern. */
export interface BulkStateForm {
    readonly locationId: number | null;
    readonly status: BookableStatus | null;
    readonly comment: string;
}

/** Ohne Ziel gibt es nichts zu senden. */
export function bulkStateProblem(form: BulkStateForm): string | null {
    return form.locationId === null && form.status === null
        ? 'Bitte einen neuen Standort und/oder Status wählen.'
        : null;
}

/** „defekt“ braucht wie beim Umbuchen eine kurze Beschreibung (Entscheidung 18). */
export function bulkCommentRequired(form: BulkStateForm): boolean {
    return form.status === 'defective';
}

export function bulkCommentProblem(form: BulkStateForm): string | null {
    return bulkCommentRequired(form) && commentLength(form.comment) < MIN_DEFECT_COMMENT_LENGTH
        ? `Bitte beschreiben Sie den Defekt mit mindestens ${String(MIN_DEFECT_COMMENT_LENGTH)} Zeichen.`
        : null;
}
