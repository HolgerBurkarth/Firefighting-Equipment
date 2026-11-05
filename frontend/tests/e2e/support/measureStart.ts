import { chromium, devices, type Page } from '@playwright/test';
import { SLOW_NETWORK, parseMeasureArgs, summaryLine } from './startTiming.ts';

/**
 * Misst den Start der App ohne Anmeldung (Plan Phase 1, Schritt 1.7d „Messen“):
 * `npm run measure:start -- [--url=…/firefighting/test/] [--runs=N] [--slow]`, nur gegen die
 * Test-Stadt; `--slow` drosselt das Netz wie ein Handy (SLOW_NETWORK).
 * Je Durchgang ein neuer Browser-Kontext in Handy-Breite (Pixel 7, Chromium): erster Besuch bis die
 * Anmeldeseite steht, dann mit Service Worker neu laden (weiterer Start). Gemessen ab dem Beginn der
 * Navigation (performance.now() der Seite) bis das Feld „E-Mail“ im DOM sichtbar ist. Liest nur
 * (GET), meldet sich nicht an; angemeldete Starts misst Holger bzw. der Agent mit Claude in Chrome.
 */

/** Merkt sich im Browser den Zeitpunkt, zu dem das Feld „E-Mail“ erscheint. */
const OBSERVE_LOGIN = `
(() => {
    const seen = () => {
        const input = document.querySelector('input[type="email"]');
        if (input !== null && input.getBoundingClientRect().height > 0) {
            window.__ffLoginVisible = performance.now();
            return true;
        }
        return false;
    };
    const observer = new MutationObserver(() => {
        if (seen()) observer.disconnect();
    });
    observer.observe(document, { childList: true, subtree: true });
})();
`;

async function loginVisible(page: Page): Promise<number> {
    await page.locator('input[type="email"]').waitFor({ state: 'visible', timeout: 30_000 });
    return page.evaluate(
        () => (window as unknown as { __ffLoginVisible?: number }).__ffLoginVisible ?? NaN,
    );
}

const parsed = parseMeasureArgs(process.argv.slice(2));
if (typeof parsed === 'string') {
    process.stderr.write(`${parsed}\n`);
    process.exit(2);
}

const browser = await chromium.launch();
const first: number[] = [];
const again: number[] = [];
try {
    for (let run = 1; run <= parsed.runs; run += 1) {
        const context = await browser.newContext({ ...devices['Pixel 7'] });
        await context.addInitScript(OBSERVE_LOGIN);
        const page = await context.newPage();
        if (parsed.slow) {
            const cdp = await context.newCDPSession(page);
            await cdp.send('Network.emulateNetworkConditions', SLOW_NETWORK);
        }
        await page.goto(new URL('anmelden', parsed.url).href);
        first.push(await loginVisible(page));
        // Weiterer Start: erst, wenn der Service Worker die Seite steuert.
        await page.waitForFunction(() => navigator.serviceWorker.controller !== null, null, {
            timeout: 30_000,
        });
        await page.reload();
        again.push(await loginVisible(page));
        await context.close();
        process.stdout.write(`Durchgang ${String(run)} fertig\n`);
    }
} finally {
    await browser.close();
}
process.stdout.write(
    `${summaryLine('Erster Besuch (Anmeldeseite)', first)}\n${summaryLine('Weiterer Start mit Service Worker', again)}\n`,
);
