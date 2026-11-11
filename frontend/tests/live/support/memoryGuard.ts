import { execFileSync } from 'node:child_process';
import { appendFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import type { Browser, CDPSession, Page } from '@playwright/test';

/**
 * Schutz und Messung für den Live-Lauf (nur mit `FF_LIVE_MEM=1`, Runde nach dem Absturz vom 11.11.2026):
 * misst alle 500 ms den JavaScript-Speicher der Seite (CDP `Performance.getMetrics`, `JSHeapUsedSize`)
 * und die Summe der Arbeitsspeicher aller Chromium-Prozesse (Windows: `tasklist`). Schutz für den Rechner:
 * über 1 GB Seite, über 3 GB alle Prozesse zusammen, oder 10 s lang ununterbrochen wachsend (mindestens
 * 100 MB mehr), oder 10 s keine Antwort der Seite → Browser sofort schließen. Nur Zahlen, nie Inhalte.
 */

export const PAGE_LIMIT_BYTES = 1024 * 1024 * 1024;
export const ALL_LIMIT_BYTES = 3 * 1024 * 1024 * 1024;
export const RISE_SECONDS = 10;
export const RISE_MIN_BYTES = 100 * 1024 * 1024;
export const SAMPLE_MS = 500;
export const SILENCE_MS = 10_000;

export type GuardVerdict = 'ok' | 'page-limit' | 'all-limit' | 'rising' | 'silent';

/** Beurteilung für Unit-Tests ohne Browser. */
export function judge(
    heapHistory: readonly number[],
    allBytes: number | null,
    sinceLastAnswerMs: number,
): GuardVerdict {
    const last = heapHistory[heapHistory.length - 1] ?? 0;
    if (last > PAGE_LIMIT_BYTES) {
        return 'page-limit';
    }
    if (allBytes !== null && allBytes > ALL_LIMIT_BYTES) {
        return 'all-limit';
    }
    if (sinceLastAnswerMs > SILENCE_MS) {
        return 'silent';
    }
    const needed = (RISE_SECONDS * 1000) / SAMPLE_MS;
    if (heapHistory.length > needed) {
        const recent = heapHistory.slice(-needed - 1);
        const rising = recent.every(
            (value, index) => index === 0 || value >= (recent[index - 1] ?? 0),
        );
        if (rising && last - (recent[0] ?? 0) >= RISE_MIN_BYTES) {
            return 'rising';
        }
    }
    return 'ok';
}

/** Summe des Arbeitsspeichers aller Chromium-Prozesse in Byte (Windows), sonst `null`. */
export function chromiumBytes(): number | null {
    if (process.platform !== 'win32') {
        return null;
    }
    let total = 0;
    for (const image of ['chrome-headless-shell.exe']) {
        try {
            const out = execFileSync(
                'tasklist',
                ['/FO', 'CSV', '/NH', '/FI', `IMAGENAME eq ${image}`],
                {
                    encoding: 'utf8',
                    timeout: 3000,
                },
            );
            for (const line of out.split(/\r?\n/)) {
                const kb = /"([\d.,\s]+) K"\s*$/.exec(line)?.[1];
                if (kb) {
                    total += Number(kb.replace(/\D/g, '')) * 1024;
                }
            }
        } catch {
            return null;
        }
    }
    return total;
}

export interface Monitor {
    /** Nach `ms` die Seite anhalten und die Aufrufliste (nur Funktionsnamen und Stellen) notieren. */
    pauseAfter: (ms: number, label: string) => void;
    /** Alle `ms` anhalten und die Aufrufliste notieren (Stichprobe, wo die Seite gerade rechnet). */
    sampleEvery: (ms: number) => void;
    stop: () => void;
    tripped: () => string | null;
}

const LOG = join(import.meta.dirname, '../../../test-results/live-memory.txt');

function note(text: string): void {
    mkdirSync(join(import.meta.dirname, '../../../test-results'), { recursive: true });
    appendFileSync(LOG, `${new Date().toISOString()} ${text}\n`);
    process.stdout.write(`MEM ${text}\n`);
}

export async function startMonitor(
    page: Page,
    browsers: readonly (Browser | null)[],
): Promise<Monitor> {
    const cdp: CDPSession = await page.context().newCDPSession(page);
    await cdp.send('Performance.enable');
    await cdp.send('Debugger.enable');
    const history: number[] = [];
    let lastAnswer = Date.now();
    let trip: string | null = null;
    let busy = false;
    let n = 0;
    let sampler: ReturnType<typeof setInterval> | undefined;
    const timer = setInterval(() => {
        if (trip !== null) {
            return;
        }
        const silence = Date.now() - lastAnswer;
        if (!busy) {
            busy = true;
            cdp.send('Performance.getMetrics')
                .then(({ metrics }) => {
                    const heap = metrics.find((m) => m.name === 'JSHeapUsedSize')?.value ?? 0;
                    lastAnswer = Date.now();
                    history.push(heap);
                    const num = (name: string): number =>
                        metrics.find((m) => m.name === name)?.value ?? 0;
                    if (n % 4 === 3) {
                        note(
                            `Skript ${num('ScriptDuration').toFixed(1)} s, Aufgaben ${num('TaskDuration').toFixed(1)} s, DOM-Knoten ${String(num('Nodes'))}, Listener ${String(num('JSEventListeners'))}`,
                        );
                    }
                    n += 1;
                    if (n % 4 === 0) {
                        const all = chromiumBytes();
                        note(
                            `Seite ${String(Math.round(heap / 1048576))} MB` +
                                (all === null
                                    ? ''
                                    : `, alle Prozesse ${String(Math.round(all / 1048576))} MB`),
                        );
                    }
                })
                .catch(() => undefined)
                .finally(() => {
                    busy = false;
                });
        }
        const verdict = judge(history, n % 4 === 0 ? chromiumBytes() : null, silence);
        if (verdict !== 'ok') {
            trip = verdict;
            note(`SCHUTZ ausgelöst: ${verdict} – Browser wird geschlossen`);
            for (const browser of browsers) {
                void browser?.close();
            }
        }
    }, SAMPLE_MS);

    cdp.on('Debugger.paused', (event) => {
        const frames = event.callFrames
            .slice(0, 8)
            .map(
                (f) =>
                    `${f.functionName || '(anonym)'} ${f.url.split('/').pop() ?? ''}:${String(f.location.lineNumber + 1)}`,
            );
        note(`PAUSE ${frames.join(' < ')}`);
        void cdp.send('Debugger.resume').catch(() => undefined);
    });

    return {
        pauseAfter: (ms, label) => {
            setTimeout(() => {
                note(`Anhalten (${label})`);
                void cdp.send('Debugger.pause').catch(() => undefined);
            }, ms);
        },
        sampleEvery: (ms) => {
            sampler = setInterval(() => {
                void cdp.send('Debugger.pause').catch(() => undefined);
            }, ms);
        },
        stop: () => {
            if (sampler) {
                clearInterval(sampler);
            }
            clearInterval(timer);
        },
        tripped: () => trip,
    };
}
