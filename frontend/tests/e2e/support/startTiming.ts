/**
 * Hilfen des Messskripts für den Start der App (measureStart.ts, Plan Phase 1, Schritt 1.7d „Messen“):
 * Aufruf lesen, Ergebnisse zusammenfassen. Ohne Browser, damit sie per Unit-Test prüfbar sind.
 */

/** Test-Stadt; Messungen nur dort (CLAUDE.md, Live-Tests). */
export const DEFAULT_MEASURE_URL = 'https://www.prodad.de/firefighting/test/';
export const DEFAULT_MEASURE_RUNS = 5;

/**
 * Gedrosseltes Handynetz für `--slow` (Chrome DevTools „Fast 4G“): 150 ms Latenz, rund 9 Mbit/s
 * herunter, 1,5 Mbit/s hinauf.
 */
export const SLOW_NETWORK = {
    offline: false,
    latency: 150,
    downloadThroughput: (9 * 1024 * 1024) / 8,
    uploadThroughput: (1.5 * 1024 * 1024) / 8,
} as const;

export interface MeasureOptions {
    readonly url: string;
    readonly runs: number;
    readonly slow: boolean;
}

/**
 * `--url=…` (nur unter /firefighting/test/), `--runs=N` (1–50) und `--slow` (gedrosselt); sonst eine
 * Meldung.
 */
export function parseMeasureArgs(args: readonly string[]): MeasureOptions | string {
    let url = DEFAULT_MEASURE_URL;
    let runs = DEFAULT_MEASURE_RUNS;
    let slow = false;
    for (const arg of args) {
        if (arg.startsWith('--url=')) {
            url = arg.slice('--url='.length);
        } else if (arg === '--slow') {
            slow = true;
        } else if (arg.startsWith('--runs=')) {
            runs = Number(arg.slice('--runs='.length));
        } else {
            return `Unbekanntes Argument: ${arg}`;
        }
    }
    let parsed: URL;
    try {
        parsed = new URL(url);
    } catch {
        return '--url erwartet eine Adresse.';
    }
    if (!parsed.pathname.endsWith('/firefighting/test/')) {
        return '--url nur für die Test-Stadt (…/firefighting/test/).';
    }
    if (!Number.isInteger(runs) || runs < 1 || runs > 50) {
        return '--runs=N erwartet eine Ganzzahl von 1 bis 50.';
    }
    return { url: parsed.href, runs, slow };
}

/** Median (bei gerader Anzahl Mittel der beiden mittleren); null ohne Werte. */
export function median(values: readonly number[]): number | null {
    if (values.length === 0) {
        return null;
    }
    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    const upper = sorted[middle] ?? 0;
    return sorted.length % 2 === 1 ? upper : ((sorted[middle - 1] ?? 0) + upper) / 2;
}

/** Eine Zeile je Fall: „Erster Besuch: 4,52 s (Median aus 5; 4,10–5,03 s)“. */
export function summaryLine(label: string, values: readonly number[]): string {
    const middle = median(values);
    if (middle === null) {
        return `${label}: keine Messung`;
    }
    const seconds = (ms: number): string => (ms / 1000).toFixed(2).replace('.', ',');
    const min = Math.min(...values);
    const max = Math.max(...values);
    return `${label}: ${seconds(middle)} s (Median aus ${String(values.length)}; ${seconds(min)}–${seconds(max)} s)`;
}
