import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    chromium,
    devices,
    expect,
    test,
    webkit,
    type Browser,
    type BrowserContext,
    type Page,
    type Route,
} from '@playwright/test';
import {
    api,
    FatalLiveError,
    login,
    otherStatus,
    record,
    STATUS_LABEL,
    storedDeviceIds,
    type LiveDevice,
    type Recorded,
} from './support/browser';
import { LiveEnvError, loadLiveAccounts, type LiveAccounts } from './support/env';
import { ACCOUNT_IDS, BASE_URL, cardTextPattern, installGuard, open } from './support/guard';
import { startMonitor, type Monitor } from './support/memoryGuard';
import { pickDevices, type Pick } from './support/pick';
import { ResultList } from './support/results';

/**
 * Live-Lauf „Offline“ gegen die Test-Stadt (`npm run test:live-offline`; CLAUDE.md, "Autonomer
 * Ablauf", Schritt 5). Zwei vorhandene Konten (aus `.env.local`), nur Ausrüstung M90–M99, danach
 * wird alles zurückgesetzt. Chromium mit echtem `context.setOffline` (Service Worker), WebKit
 * (iPhone 13) nur mit abgebrochenen API-Anfragen: Dort umgeht `setOffline` den Service Worker, es
 * gibt also weder Start noch Neuladen ohne Netz, keine Kamera und keinen Flugmodus.
 */

interface Original {
    version: number;
    status: string;
    locationId: number;
}

const WEBKIT_LIMIT =
    'Grenze WebKit (iPhone 13): App-Logik, IndexedDB, Warteschlange und Senden mit abgebrochenen ' +
    'API-Anfragen; kein echtes Offline (Neuladen und Start ohne Netz), keine Kamera, kein Flugmodus.';

function sent(list: Recorded[], path: string): Recorded[] {
    return list.filter(
        (entry) => entry.method === 'POST' && entry.path === path && entry.status === 200,
    );
}

/** Die Buchung ging genau einmal raus, mit Aktionszeit und client_ref. */
async function expectSentOnce(list: Recorded[], path: string): Promise<void> {
    // Die Antwort wird erst nach dem Zustand „angekommen“ aufgezeichnet: kurz warten, dann genau prüfen
    // (zu viele Antworten fallen im Wartefenster auf, weil es nur bei 0 weitergeht).
    await expect.poll(() => sent(list, path).length, { timeout: 30_000 }).toBeGreaterThan(0);
    const entries = sent(list, path);
    expect(entries, `Antworten 200 auf POST ${path}`).toHaveLength(1);
    expect(entries[0]?.request).toMatchObject({
        client_ref: expect.stringMatching(/^[0-9a-f-]{36}$/),
        occurred_at: expect.stringMatching(/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/),
    });
}

async function openDevice(page: Page, identifier: string): Promise<void> {
    await open(page, 'suchen');
    await page.getByRole('searchbox').fill(identifier);
    // Der Text der Karte ist zusammengesetzt („M94Live-Testtyp 2 …“): kein \b nach der Nummer, sondern
    // „nicht von einer weiteren Ziffer gefolgt“ (sonst fände M9 auch M94). Mit \b wartete der Klick
    // ohne Ende auf eine Karte, die längst da war (Live-Lauf 11.11.2026).
    await page
        .getByTestId('device-card')
        .filter({ hasText: cardTextPattern(identifier) })
        .first()
        .click();
    await expect(
        page.getByRole('heading', { level: 1, name: new RegExp(`^${identifier} ·`) }),
    ).toBeVisible();
}

/**
 * Diagnose (nur für die Fehlersuche, Umgebungsvariablen): `FF_LIVE_NET=route` bricht API-Anfragen
 * ab statt `setOffline`, `FF_LIVE_CHANNEL=chrome` nimmt das installierte Chrome, `FF_LIVE_DIAG=1`
 * schreibt Abstürze, Skriptfehler und Fehlermeldungen der Seiten (nur Text, gekürzt).
 */
const NET_MODE = process.env['FF_LIVE_NET'] === 'route' ? 'route' : 'offline';
const abortApi = async (route: Route): Promise<void> => {
    await route.abort();
};

async function setNet(context: BrowserContext, offline: boolean): Promise<void> {
    if (NET_MODE === 'offline') {
        await context.setOffline(offline);
        return;
    }
    if (offline) {
        await context.route('**/api/**', abortApi);
    } else {
        await context.unroute('**/api/**', abortApi);
    }
}

function diagnose(page: Page, name: string): void {
    if (process.env['FF_LIVE_DIAG'] !== '1') {
        return;
    }
    const say = (text: string): void => {
        process.stdout.write(`DIAG ${name} ${text.slice(0, 300)}
`);
    };
    page.on('crash', () => {
        say('CRASH der Seite');
    });
    page.on('pageerror', (error) => {
        say(`pageerror ${error.message.slice(0, 200)}`);
    });
    page.on('console', (message) => {
        if (message.type() === 'error' || message.type() === 'warning') {
            say(`console.${message.type()} ${message.text()}`);
        }
    });
    page.context().on('serviceworker', (worker) => {
        say('Service Worker gestartet');
        worker.on('close', () => {
            say('Service Worker beendet');
        });
    });
}

async function bookStatus(
    page: Page,
    identifier: string,
    status: 'ready' | 'maintenance',
    beforeSave?: () => void,
): Promise<void> {
    const mark = (text: string): void => {
        if (process.env['FF_LIVE_DIAG'] === '1') {
            process.stdout.write(`DIAG step ${text}
`);
        }
    };
    mark('oeffnen');
    await openDevice(page, identifier);
    mark('Status waehlen');
    await page.getByRole('button', { name: STATUS_LABEL[status] ?? status }).click();
    mark('Speichern');
    beforeSave?.();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
    await expect(page.getByTestId('booking-notice')).toContainText('wird gesendet');
    await expect(
        page.getByTestId('queued-waiting').filter({ hasText: `${identifier} →` }),
    ).toBeVisible();
}

async function swapOffline(page: Page, out: string, replacement: string): Promise<void> {
    await openDevice(page, out);
    await page.getByTestId('swap-open').click();
    const dialog = page.getByTestId('form-dialog');
    const radio = dialog.getByRole('radio', { name: new RegExp(`\\b${replacement}\\b`) });
    if (!(await radio.isVisible())) {
        await dialog.getByTestId('swap-show-others').click();
    }
    await radio.check();
    await dialog.getByRole('radio', { name: 'defekt' }).check();
    await dialog.getByRole('button', { name: 'Tauschen', exact: true }).click();
    await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
    await expect(
        page.getByTestId('queued-waiting').filter({ hasText: `${out} ⇄ ${replacement}` }),
    ).toBeVisible();
}

async function waitForServiceWorker(page: Page): Promise<void> {
    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
    });
    const controlled = await page.evaluate(() => navigator.serviceWorker.controller !== null);
    if (!controlled) {
        await page.reload();
    }
    await expect
        .poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null))
        .toBe(true);
}

async function waitForCopy(page: Page, accountId: number): Promise<void> {
    await open(page, 'suchen');
    await expect
        .poll(async () => (await storedDeviceIds(page, accountId))?.length ?? 0, {
            timeout: 60_000,
        })
        .toBeGreaterThan(0);
}

function sendNow(page: Page): Promise<void> {
    return page.evaluate(() => {
        window.dispatchEvent(new Event('online'));
    });
}

async function fresh(page: Page, id: number): Promise<LiveDevice> {
    const response = await api<{ device: LiveDevice }>(page, 'GET', `devices/${String(id)}`);
    expect(response.status, `GET devices/${String(id)}`).toBe(200);
    return response.data.device;
}

/** Stellt jede berührte Ausrüstung auf den Stand vom Anfang zurück (online, mit Konto 1). */
async function resetAll(page: Page, originals: Map<number, Original>): Promise<string[]> {
    const notes: string[] = [];
    for (const [id, original] of originals) {
        try {
            const now = await fresh(page, id);
            if (now.status === original.status && now.location.id === original.locationId) {
                continue;
            }
            const response = await api(page, 'POST', `devices/${String(id)}/state`, {
                version: now.version,
                location_id: original.locationId,
                status: original.status,
                comment: 'Live-Test zurückgesetzt',
            });
            if (response.status !== 200) {
                notes.push(
                    `${now.identifier}: Zurücksetzen scheiterte (HTTP ${String(response.status)})`,
                );
            }
        } catch {
            notes.push(`ID ${String(id)}: Zurücksetzen scheiterte`);
        }
    }
    return notes;
}

interface Run {
    results: ResultList;
    violations: string[];
}

interface Holder {
    p1?: Page;
    bookingId?: number;
    monitor?: Monitor;
}

async function chromiumPart(
    run: Run,
    browser: Browser,
    browser2: Browser | null,
    accounts: LiveAccounts,
    allowed: Set<number>,
    originals: Map<number, Original>,
    holder: Holder,
): Promise<void> {
    const { results } = run;
    const newContext = async (target: Browser): Promise<BrowserContext> => {
        const context = await target.newContext({
            locale: 'de-DE',
            timezoneId: 'Europe/Berlin',
            serviceWorkers: 'allow',
        });
        await installGuard(context, allowed, run.violations);
        return context;
    };
    // Konto 2 läuft in einem eigenen Browser (nicht nur in einem eigenen Kontext): Zwei Kontexte
    // mit Service Worker in EINEM Chromium stürzten im Live-Lauf gemeinsam ab (11.11.2026).
    // `FF_LIVE_ONE=1` (Diagnose): nur Konto 1, Lauf endet nach C6.
    const ctx1 = await newContext(browser);
    const ctx2: BrowserContext | undefined = browser2 ? await newContext(browser2) : undefined;
    const p1 = await ctx1.newPage();
    const p2: Page | undefined = ctx2 ? await ctx2.newPage() : undefined;
    holder.p1 = p1;
    diagnose(p1, 'p1');
    let monitor = null as Monitor | null;
    const rec1 = record(p1);
    const rec2 = p2 ? record(p2) : [];

    await login(p1, accounts.user1, ACCOUNT_IDS.user1, 'Konto 1');
    if (p2) {
        diagnose(p2, 'p2');
        await login(p2, accounts.user2, ACCOUNT_IDS.user2, 'Konto 2');
    }

    // Diagnose Speicher (FF_LIVE_MEM=1): Messung und Schutz, siehe support/memoryGuard.ts.
    if (process.env['FF_LIVE_MEM'] === '1') {
        monitor = await startMonitor(p1, [browser, browser2]);
        holder.monitor = monitor;
        monitor.sampleEvery(3000);
        p1.setDefaultTimeout(30_000);
    }
    const listed = await api<{ devices: LiveDevice[] }>(p1, 'GET', 'devices?q=M9&limit=200');
    const listed2 = p2
        ? await api<{ devices: LiveDevice[] }>(p2, 'GET', 'devices?q=M9&limit=200')
        : listed;
    const visible2 = new Set(listed2.data.devices.map((device) => device.id));
    const pick: Pick = pickDevices(listed.data.devices, visible2);
    for (const device of Object.values(pick)) {
        if (device) {
            allowed.add(device.id);
            originals.set(device.id, {
                version: device.version,
                status: device.status,
                locationId: device.location.id,
            });
        }
    }

    // --- Konto 1 (Benutzer): Start, Streifen, Suche, Umbuchen, Abmelden, Kontowechsel, Senden ---
    const a = pick.booking;
    if (a) {
        holder.bookingId = a.id;
        const target = otherStatus(a.status);
        await open(p1, 'suchen');
        await results.step(
            'C1',
            'Chromium: Service Worker kontrolliert die Seite, Kopie in IndexedDB',
            async () => {
                await waitForServiceWorker(p1);
                await waitForCopy(p1, ACCOUNT_IDS.user1);
            },
        );
        await results.step(
            'C2',
            'Chromium: echtes Offline – Neuladen ohne Netz zeigt den Streifen, nicht die Anmeldung',
            async () => {
                await setNet(ctx1, true);
                await p1.reload();
                await expect(p1.getByTestId('offline-notice')).toContainText(
                    'Offline – angezeigt wird der Stand vom',
                );
                await expect(p1).not.toHaveURL(/anmelden/);
            },
        );
        await results.step('C3', 'Chromium: Suche aus der Kopie ohne Netz', async () => {
            await open(p1, 'suchen');
            await p1.getByRole('searchbox').fill(a.identifier);
            await expect(
                p1.getByTestId('device-card').filter({ hasText: a.identifier }).first(),
            ).toBeVisible();
        });
        await results.step(
            'C4',
            `Chromium: ${a.identifier} offline umbuchen (→ ${STATUS_LABEL[target] ?? target}), Warteschlange wartet`,
            async () => {
                await bookStatus(p1, a.identifier, target, () => {
                    for (const ms of [1000, 3000, 6000]) {
                        monitor?.pauseAfter(ms, `nach Speichern +${String(ms)} ms`);
                    }
                });
                expect(sent(rec1, `devices/${String(a.id)}/state`)).toHaveLength(0);
            },
        );

        // Wieder Verbindung, aber das Senden von Buchungen bleibt gesperrt (Anfragen scheitern).
        const blockState = async (route: Route): Promise<void> => {
            await route.abort();
        };
        await ctx1.route('**/api/v1/devices/*/state', blockState);
        await setNet(ctx1, false);
        await results.step(
            'C5',
            'Chromium: Abmelden mit wartender Buchung fragt nach, „Abbrechen“ behält alles',
            async () => {
                await open(p1, 'profil');
                await p1.getByRole('button', { name: 'Abmelden' }).click();
                const confirm = p1.getByTestId('confirm-dialog');
                await expect(confirm).toContainText('1 Buchung wurde noch nicht gesendet');
                await confirm.getByRole('button', { name: 'Abbrechen' }).click();
                await expect(p1).toHaveURL(/\/firefighting\/test\/profil$/);
                await open(p1, 'suchen');
                await expect(
                    p1.getByTestId('queued-waiting').filter({ hasText: `${a.identifier} →` }),
                ).toBeVisible();
            },
        );
        await results.step(
            'B8a',
            'Kontowechsel ohne Abmelden: Warteschlange von Konto 1 bleibt, Konto 2 sieht den Hinweis, Konto 1 hat sie danach wieder',
            async () => {
                await ctx1.clearCookies();
                await login(p1, accounts.user2, ACCOUNT_IDS.user2, 'Konto 2');
                await open(p1, 'suchen');
                await expect(p1.getByTestId('other-queue')).toContainText(
                    'nicht gesendete Buchung von',
                );
                await expect(p1.getByTestId('booking-queue')).toHaveCount(0);
                await ctx1.clearCookies();
                await login(p1, accounts.user1, ACCOUNT_IDS.user1, 'Konto 1');
                await open(p1, 'suchen');
                await expect(
                    p1.getByTestId('queued-waiting').filter({ hasText: `${a.identifier} →` }),
                ).toBeVisible();
                await expect(p1.getByTestId('other-queue')).toHaveCount(0);
            },
        );
        await ctx1.unroute('**/api/v1/devices/*/state', blockState);
        await results.step(
            'C6',
            'Chromium: Senden nach der Verbindung genau einmal, mit Aktionszeit und client_ref',
            async () => {
                await sendNow(p1);
                await expect(
                    p1.getByTestId('queued-done').filter({ hasText: a.identifier }),
                ).toBeVisible();
                await expectSentOnce(rec1, `devices/${String(a.id)}/state`);
                expect((await fresh(p1, a.id)).status).toBe(target);
            },
        );
    } else {
        results.skip(
            'C1–C6',
            'Konto 1: Offline-Umbuchen',
            'keine passende Ausrüstung M90–M99 (einsatzbereit, für beide Konten sichtbar)',
        );
    }

    if (!p2 || !ctx2) {
        return;
    }

    // --- Konto 2 (Geräteträger): Benutzt melden und Tausch offline ---
    await waitForCopy(p2, ACCOUNT_IDS.user2);
    const config = await api<{ used_target?: { name?: string } | null }>(
        p2,
        'GET',
        'client-config',
    );
    await setNet(ctx2, true);
    await p2.reload();
    await expect(p2.getByTestId('offline-notice')).toBeVisible();
    const queued: string[] = [];

    if (!pick.used) {
        results.skip('C7', 'Benutzt melden offline', 'keine passende Ausrüstung');
    } else if (!config.data.used_target) {
        results.skip(
            'C7',
            'Benutzt melden offline',
            'keine Sammelstelle in der Test-Stadt freigeschaltet (Kleiderkammer)',
        );
    } else {
        const used = pick.used;
        const ok = await results.step(
            'C7',
            `Chromium: ${used.identifier} offline „Benutzt melden“ (Rückfrage, Warteschlange)`,
            async () => {
                await openDevice(p2, used.identifier);
                await p2.getByTestId('report-used').click();
                await p2
                    .getByTestId('confirm-dialog')
                    .getByRole('button', { name: 'Benutzt melden' })
                    .click();
                await expect(p2).toHaveURL(/\/firefighting\/test\/suchen$/);
                await expect(
                    p2.getByTestId('queued-waiting').filter({ hasText: `${used.identifier} →` }),
                ).toBeVisible();
            },
        );
        if (ok) {
            queued.push(`devices/${String(used.id)}/used`);
        }
    }
    if (pick.swapOut && pick.swapIn) {
        const { swapOut, swapIn } = pick;
        const ok = await results.step(
            'C8',
            `Chromium: ${swapOut.identifier} ⇄ ${swapIn.identifier} offline tauschen (Warteschlange)`,
            async () => {
                await swapOffline(p2, swapOut.identifier, swapIn.identifier);
            },
        );
        if (ok) {
            queued.push('devices/swap');
        }
    } else {
        results.skip('C8', 'Tausch offline', 'kein Paar gleichen Typs an verschiedenen Standorten');
    }
    await setNet(ctx2, false);
    await sendNow(p2);
    await results.step(
        'C9',
        'Chromium: Konto 2 sendet nach der Verbindung jede Buchung genau einmal (Aktionszeit, client_ref)',
        async () => {
            expect(queued.length, 'Es wurde nichts in die Warteschlange gelegt').toBeGreaterThan(0);
            await expect(p2.getByTestId('queued-waiting')).toHaveCount(0);
            for (const path of queued) {
                await expectSentOnce(rec2, path);
            }
        },
    );

    // --- „Ersatz inzwischen woanders“ ---
    if (pick.lateOut && pick.lateIn) {
        const { lateOut, lateIn } = pick;
        await results.step(
            'C10',
            `Chromium: Konto 2 tauscht ${lateOut.identifier} ⇄ ${lateIn.identifier} offline, Konto 1 bucht den Ersatz online um – Hinweis im Ergebnis`,
            async () => {
                await open(p2, 'suchen');
                await setNet(ctx2, true);
                await expect(p2.getByTestId('offline-notice')).toBeVisible();
                await swapOffline(p2, lateOut.identifier, lateIn.identifier);
                const now = await fresh(p1, lateIn.id);
                const moved = await api(p1, 'POST', `devices/${String(lateIn.id)}/state`, {
                    version: now.version,
                    status: 'maintenance',
                    comment: 'Live-Test: Ersatz inzwischen umgebucht',
                });
                expect(moved.status, 'Konto 1 bucht den Ersatz online um').toBe(200);
                await setNet(ctx2, false);
                await sendNow(p2);
                const expected = queued.includes('devices/swap') ? 2 : 1;
                await expect
                    .poll(() => sent(rec2, 'devices/swap').length, { timeout: 60_000 })
                    .toBe(expected);
                const last = sent(rec2, 'devices/swap')[expected - 1];
                const answer = (await last?.response) as {
                    result?: { notice_id?: unknown };
                } | null;
                expect(answer?.result?.notice_id, 'Hinweis (notice_id) im Ergebnis').toBeTruthy();
            },
        );
    } else {
        results.skip(
            'C10',
            'Ersatz inzwischen woanders',
            'kein zweites Paar gleichen Typs an verschiedenen Standorten',
        );
    }
    await setNet(ctx2, false);
}

async function webkitPart(
    run: Run,
    accounts: LiveAccounts,
    allowed: Set<number>,
    holder: Holder,
): Promise<void> {
    const { results } = run;
    if (holder.bookingId === undefined || !holder.p1) {
        results.skip('W1–W3', 'WebKit (iPhone 13)', 'keine Ausrüstung');
        return;
    }
    const bookingId = holder.bookingId;
    const browser = await webkit.launch();
    try {
        const context = await browser.newContext({
            ...devices['iPhone 13'],
            locale: 'de-DE',
            timezoneId: 'Europe/Berlin',
            serviceWorkers: 'allow',
        });
        await installGuard(context, allowed, run.violations);
        const page = await context.newPage();
        const rec = record(page);
        await login(page, accounts.user1, ACCOUNT_IDS.user1, 'Konto 1 (WebKit)');
        const now = await fresh(page, bookingId);
        const target = otherStatus(now.status);
        await waitForCopy(page, ACCOUNT_IDS.user1);
        const blockApi = async (route: Route): Promise<void> => {
            await route.abort();
        };
        await results.step(
            'W1',
            'WebKit (iPhone 13): Kopie in IndexedDB; Neuladen mit abgebrochenen API-Anfragen zeigt den Streifen',
            async () => {
                await context.route('**/api/v1/**', blockApi);
                await page.reload();
                await expect(page.getByTestId('offline-notice')).toContainText(
                    'Offline – angezeigt wird der Stand vom',
                );
            },
        );
        await results.step(
            'W2',
            `WebKit: ${now.identifier} aus der Kopie finden und umbuchen, Warteschlange wartet`,
            async () => {
                await bookStatus(page, now.identifier, target);
            },
        );
        await context.unroute('**/api/v1/**', blockApi);
        await results.step(
            'W3',
            'WebKit: Senden genau einmal, mit Aktionszeit und client_ref',
            async () => {
                await sendNow(page);
                await expect(
                    page.getByTestId('queued-done').filter({ hasText: now.identifier }),
                ).toBeVisible();
                await expectSentOnce(rec, `devices/${String(bookingId)}/state`);
            },
        );
    } finally {
        await browser.close();
    }
}

let partialResults: ResultList | null = null;

async function main(): Promise<ResultList> {
    const results = new ResultList();
    partialResults = results;
    const run: Run = { results, violations: [] };
    const accounts = loadLiveAccounts();
    const allowed = new Set<number>();
    const originals = new Map<number, Original>();
    const holder: Holder = {};
    const one = process.env['FF_LIVE_ONE'] === '1';
    const logFile = process.env['FF_LIVE_LOGFILE'];
    const launchOptions = {
        ...(process.env['FF_LIVE_CHANNEL'] === 'chrome' && { channel: 'chrome' as const }),
        ...(logFile && { args: ['--enable-logging', '--v=1', `--log-file=${logFile}`] }),
    };
    const chrome = await chromium.launch(launchOptions);
    const chrome2 = one ? null : await chromium.launch();
    try {
        await chromiumPart(run, chrome, chrome2, accounts, allowed, originals, holder);
        if (!one) {
            await webkitPart(run, accounts, allowed, holder);
        }
    } finally {
        holder.monitor?.stop();
        if (holder.p1) {
            const notes = await resetAll(holder.p1, originals);
            results.items.push({
                id: 'R1',
                text: 'Ausrüstung auf den Stand vom Anfang zurückgesetzt',
                mark: notes.length === 0 ? '☑' : '☒',
                ...(notes.length > 0 && { detail: notes.join('; ') }),
            });
        }
        await chrome2?.close();
        await chrome.close();
        if (run.violations.length > 0) {
            results.items.push({
                id: 'G1',
                text: 'Schutz (nur Test-Stadt, nur M90–M99)',
                mark: '☒',
                detail: [...new Set(run.violations)].join('; '),
            });
        }
    }
    return results;
}

async function execute(): Promise<void> {
    let results: ResultList | null = null;
    let fatal: string | null = null;
    try {
        results = await main();
    } catch (error) {
        fatal =
            error instanceof FatalLiveError || error instanceof LiveEnvError
                ? error.message
                : error instanceof Error
                  ? (error.message.split('\n')[0] ?? 'Fehler')
                  : 'Fehler';
    }
    const text = [
        `Live-Lauf Offline ${new Date().toISOString()} – ${BASE_URL}`,
        WEBKIT_LIMIT,
        (results ?? partialResults)?.render() ?? '(keine Ergebnisse)',
        fatal ? `ABBRUCH: ${fatal}` : '',
    ]
        .filter((line) => line !== '')
        .join('\n');
    const folder = join(import.meta.dirname, '../../test-results');
    mkdirSync(folder, { recursive: true });
    writeFileSync(join(folder, 'live-offline.txt'), `${text}\n`);
    process.stdout.write(`\n${text}\n`);
    expect(fatal).toBeNull();
    expect(results?.failed ?? 1).toBe(0);
}

test('Live-Lauf Offline (Test-Stadt, M90–M99)', async () => {
    await execute();
});
