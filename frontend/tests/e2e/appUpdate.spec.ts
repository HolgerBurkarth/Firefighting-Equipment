import { randomUUID } from 'node:crypto';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Page } from '@playwright/test';
import { START_CHECK_TIMEOUT_MS, START_LOAD_TIMEOUT_MS } from '../../src/runtime/appUpdate';
import {
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test as base,
    type ApiMocks,
    type MockResponse,
} from './fixtures';
import { NEXT_BUILD_ID } from './globalSetup';
import { ADMIN_OVERVIEW } from './support/admin';
import { isAbortedFontDownload } from './support/consoleErrors';
import { LOCATIONS, deviceDetail, deviceJson } from './support/devices';
import { deviceTypeJson } from './support/masterData';

/**
 * Updates mit zwei echten Builds: Der Update-Testserver des Workers (tests/e2e/support/
 * update-server.php) liefert je Bereich frontend/dist (alt) oder test-builds/next (neu). Jeder Test
 * hat einen eigenen Bereich und damit einen eigenen Service Worker.
 */
const test = base.extend({
    baseURL: async ({ updateServer }, use) => {
        await use(updateServer.origin);
    },
});
const STATE = fileURLToPath(new URL('../../test-builds/state/', import.meta.url));

test.use({ serviceWorkers: 'allow' });

let areas = 0;

/**
 * Eigener Bereich je Test. Eindeutig über alle Läufe: Prozess-IDs werden unter Windows schnell
 * wiederverwendet, ein neuer Worker hätte sonst den (schon umgestellten) Bereich eines alten.
 */
function newArea(project: string): string {
    areas += 1;
    return `upd-${project}-${String(areas)}-${randomUUID().slice(0, 8)}`;
}

/** Alte Version laden und warten, bis ihr Service Worker die Seite kontrolliert. */
async function openOldVersion(page: Page, area: string): Promise<void> {
    await page.goto(`/firefighting/${area}/anmelden`);
    await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null);
    await expect(page.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);
}

/** Deploy: Der Server liefert ab jetzt die neue Version; die App prüft wie beim Zurückkehren. */
async function deployAndCheck(page: Page, area: string): Promise<void> {
    writeFileSync(join(STATE, area), 'next');
    await page.evaluate(() => {
        document.dispatchEvent(new Event('visibilitychange'));
    });
    // waitForFunction wartet nicht auf Promises; daher abfragen, bis der neue Worker wartet.
    await expect
        .poll(() =>
            page.evaluate(async () => {
                const registration = await navigator.serviceWorker.getRegistration();
                return registration?.waiting?.state ?? null;
            }),
        )
        .toBe('installed');
}

test.describe('Updates der App', () => {
    test('ohne Eingabe: beim nächsten Seitenwechsel unbemerkt die neue Version', async ({
        page,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        const email = page.getByLabel('E-Mail-Adresse');
        // Mit Eingabe zeigt der Hinweis, dass die neue Version bereitsteht …
        await email.fill('a');
        // Die erste Installation des Service Workers ist kein Update.
        await expect(page.getByTestId('update-notice')).toBeHidden();
        await deployAndCheck(page, area);
        await expect(page.getByTestId('update-notice')).toBeVisible();
        // … ohne Eingabe verschwindet er, die App wartet still auf den nächsten Seitenwechsel.
        await email.fill('');
        await expect(page.getByTestId('update-notice')).toBeHidden();
        await expect(page.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);

        await page.getByRole('link', { name: 'Passwort vergessen?' }).click();
        await expect(page.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(page).toHaveURL(`/firefighting/${area}/passwort-vergessen`);
        await expect(
            page.getByRole('heading', { level: 1, name: 'Passwort vergessen' }),
        ).toBeVisible();
        await expect(page.getByTestId('update-notice')).toBeHidden();
    });

    test('mit Eingabe: Hinweis statt Neuladen, Eingabe bleibt erhalten, Knopf mit Rückfrage', async ({
        page,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        await page.getByLabel('E-Mail-Adresse').fill('anna@example.org');
        await page.getByLabel('Passwort', { exact: true }).fill('geheim-und-lang');
        // Merkmal der geladenen Seite: Es verschwindet nur bei einem Neuladen.
        await page.evaluate(() => {
            (window as unknown as { ffMarker: boolean }).ffMarker = true;
        });

        await deployAndCheck(page, area);
        const notice = page.getByTestId('update-notice');
        await expect(notice).toContainText(
            'Neue Version verfügbar – nach dem Speichern wird aktualisiert.',
        );
        await expect(page.getByLabel('E-Mail-Adresse')).toHaveValue('anna@example.org');
        await expect(page.getByLabel('Passwort', { exact: true })).toHaveValue('geheim-und-lang');
        expect(
            await page.evaluate(() => (window as unknown as { ffMarker?: boolean }).ffMarker),
        ).toBe(true);
        await expect(page.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);

        // Per Knopf erst eine Rückfrage; Standard (Fokus, Enter) ist „Abbrechen“.
        const updateButton = notice.getByRole('button', { name: 'Jetzt aktualisieren' });
        await updateButton.click();
        const dialog = page.getByRole('alertdialog', { name: 'Jetzt aktualisieren?' });
        await expect(dialog).toContainText(
            'Ihre Eingaben auf dieser Seite gehen verloren. Trotzdem aktualisieren?',
        );
        await expect(dialog.getByRole('button', { name: 'Abbrechen' })).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(dialog).toBeHidden();
        await expect(page.getByLabel('E-Mail-Adresse')).toHaveValue('anna@example.org');
        await expect(page.getByLabel('Passwort', { exact: true })).toHaveValue('geheim-und-lang');
        expect(
            await page.evaluate(() => (window as unknown as { ffMarker?: boolean }).ffMarker),
        ).toBe(true);

        // Escape bricht ebenso ab.
        await updateButton.click();
        await expect(dialog).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(page.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);

        // Bewusst bestätigt: neue Version sofort.
        await updateButton.click();
        await dialog.getByRole('button', { name: 'Aktualisieren' }).click();
        await expect(page.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(page).toHaveURL(`/firefighting/${area}/anmelden`);
        await expect(page.getByTestId('update-notice')).toBeHidden();
    });
});

/** Nur den Server umstellen (Deploy), ohne Prüfung durch die App. */
function deploy(area: string): void {
    writeFileSync(join(STATE, area), 'next');
}

/** Die App prüft wie beim Zurückkehren in den Vordergrund. */
async function triggerCheck(page: Page): Promise<void> {
    await page.evaluate(() => {
        document.dispatchEvent(new Event('visibilitychange'));
    });
}

/** Wartet, bis eine neue Version installiert ist und wartet. */
async function waitForWaitingWorker(page: Page): Promise<void> {
    await expect
        .poll(() =>
            page.evaluate(async () => {
                const registration = await navigator.serviceWorker.getRegistration();
                return registration?.waiting?.state ?? null;
            }),
        )
        .toBe('installed');
}

/** Wartet, bis kein Worker mehr installiert wird oder wartet und einer die Seite kontrolliert. */
async function waitForSettledWorker(page: Page): Promise<void> {
    await expect
        .poll(() =>
            page.evaluate(async () => {
                const registration = await navigator.serviceWorker.getRegistration();
                return (
                    navigator.serviceWorker.controller !== null &&
                    registration?.installing === null &&
                    registration.waiting === null &&
                    registration.active?.state === 'activated'
                );
            }),
        )
        .toBe(true);
}

/** Seitenwechsel in der App; danach muss die neue Version geladen sein. */
async function expectNewVersionAfterNavigation(page: Page, area: string): Promise<void> {
    await page.getByRole('link', { name: 'Passwort vergessen?' }).click();
    await expect(page).toHaveURL(`/firefighting/${area}/passwort-vergessen`);
    await expect(page.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
    await expect(page.getByRole('heading', { level: 1, name: 'Passwort vergessen' })).toBeVisible();
}

test.describe('Updates der App: Seiten ohne Kontrolle des Service Workers, mehrere Tabs', () => {
    test('erster Besuch, Deploy noch vor der Installation des Service Workers', async ({
        page,
    }, testInfo) => {
        // Die Registrierung wartet, bis der Test sie freigibt (nur ohne Service Worker in Kontrolle):
        // So installiert der erste Besuch schon den Service Worker der NEUEN Version, die Seite läuft
        // aber noch mit der alten. Der neue übernimmt sie (clientsClaim) wie eine Erstinstallation.
        await page.addInitScript(() => {
            const container = navigator.serviceWorker;
            if (container.controller !== null) {
                return;
            }
            const register = container.register.bind(container);
            let release: () => void = () => undefined;
            const gate = new Promise<void>((resolve) => {
                release = resolve;
            });
            (window as unknown as { ffReleaseSw: () => void }).ffReleaseSw = () => {
                release();
            };
            container.register = (...args: Parameters<typeof register>) =>
                gate.then(() => register(...args));
        });
        const area = newArea(testInfo.project.name);
        await page.goto(`/firefighting/${area}/anmelden`);
        // Die alte Version läuft (der Start wartet höchstens kurz auf die zurückgehaltene
        // Registrierung, dann erscheint die Seite).
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
        await expect(page.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);

        deploy(area);
        await page.evaluate(() => {
            (window as unknown as { ffReleaseSw: () => void }).ffReleaseSw();
        });
        await waitForSettledWorker(page);
        await expectNewVersionAfterNavigation(page, area);
    });

    test('Seite per Strg+F5 geladen, neue Version übernimmt sie (nur Chromium)', async ({
        page,
        browserName,
    }, testInfo) => {
        // Strg+F5 (Neuladen ohne Cache und ohne Service Worker) gibt es nur über das Chrome DevTools
        // Protocol; in Firefox und WebKit bildet der Test oben denselben Zustand nach (Seite ohne
        // Kontrolle, die ein neuer Service Worker übernimmt).
        test.skip(browserName !== 'chromium', 'Strg+F5 nur in Chromium nachstellbar');
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        const cdp = await page.context().newCDPSession(page);
        await cdp.send('Page.enable');
        const loaded = new Promise<void>((resolve) => {
            cdp.once('Page.loadEventFired', () => {
                resolve();
            });
        });
        await cdp.send('Page.reload', { ignoreCache: true });
        await loaded;
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
        expect(await page.evaluate(() => navigator.serviceWorker.controller === null)).toBe(true);

        // Deploy: Der neue Service Worker hat keine kontrollierten Seiten, wird sofort aktiv und
        // übernimmt die Seite, die noch die alte Version zeigt.
        deploy(area);
        await triggerCheck(page);
        await waitForSettledWorker(page);
        await expect(page.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expectNewVersionAfterNavigation(page, area);
    });

    test('zwei Tabs: einer aktualisiert, der andere folgt beim nächsten Seitenwechsel', async ({
        page,
        context,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        const second = await context.newPage();
        await openOldVersion(second, area);

        deploy(area);
        await triggerCheck(page);
        await expect
            .poll(() =>
                page.evaluate(async () => {
                    const registration = await navigator.serviceWorker.getRegistration();
                    return registration?.waiting?.state ?? null;
                }),
            )
            .toBe('installed');
        await expectNewVersionAfterNavigation(page, area);
        // Der zweite Tab läuft noch mit der alten Version unter dem neuen Service Worker.
        await expect(second.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expectNewVersionAfterNavigation(second, area);
        await second.close();
    });

    test('zwei Tabs, einer geschlossen: der übrige aktualisiert beim Seitenwechsel', async ({
        page,
        context,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        const second = await context.newPage();
        await openOldVersion(second, area);

        deploy(area);
        await triggerCheck(second);
        await expect
            .poll(() =>
                second.evaluate(async () => {
                    const registration = await navigator.serviceWorker.getRegistration();
                    return registration?.waiting?.state ?? null;
                }),
            )
            .toBe('installed');
        await second.close();
        await expectNewVersionAfterNavigation(page, area);
    });
});

/**
 * API eines Admins mit Gerätedetail (M1) und Ausrüstungsliste, als feste Antworten (Funktionen einmal mit
 * einer Anfrage ohne Query ausgewertet).
 */
function adminDeviceApi(area: string): Record<string, MockResponse> {
    const api: ApiMocks = {
        'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
        ...ADMIN_OVERVIEW,
        ...LOCATIONS,
        // Das Dashboard und die Zähler im Menü fragen den Sollbestand (T21).
        'GET api/v1/stock': {
            status: 200,
            body: { locations: [], summary: { locations: 0, out_of_service: 0 } },
        },
        'GET api/v1/device-types': {
            status: 200,
            body: { device_types: [deviceTypeJson(1, 'Atemschutzmaske', 1)] },
        },
        ...deviceDetail(deviceJson(1)),
        'GET api/v1/devices/1/events': {
            status: 200,
            body: { events: [], total: 0, limit: 20, offset: 0 },
        },
    };
    return Object.fromEntries(
        Object.entries(api).map(([key, response]) => {
            const [method = '', path = ''] = key.split(' ');
            const url = `http://localhost/firefighting/${area}/${path}`;
            return [
                key,
                typeof response === 'function'
                    ? response({ area, method, path, url, body: null })
                    : response,
            ];
        }),
    );
}

/**
 * Antworten des Update-Testservers für den Bereich (update-server.php). Für Tabs ohne Wächter: In
 * WebKit erreichen Anfragen einer vom Service Worker kontrollierten Seite page.route() nicht.
 */
function writeApiTable(area: string, api: Record<string, MockResponse>): void {
    writeFileSync(join(STATE, `${area}.api.json`), JSON.stringify(api));
}

/**
 * Fehler eines weiteren Tabs (ohne Wächter aus fixtures.ts): Konsolenfehler und Skriptfehler, mit
 * derselben Ausnahme für abgebrochene Schrift-Downloads in Firefox.
 */
function collectProblems(page: Page, browserName: string, origin: string): string[] {
    const problems: string[] = [];
    page.on('pageerror', (error) => problems.push(`Skriptfehler: ${error.message}`));
    page.on('console', (message) => {
        if (
            message.type() === 'error' &&
            !isAbortedFontDownload(message.text(), browserName, origin)
        ) {
            problems.push(`Konsolenfehler: ${message.text()}`);
        }
    });
    return problems;
}

/** „controlled“ bzw. „uncontrolled“, dazu „installing“ und „waiting“ der Registrierung. */
function workerState(page: Page): Promise<string> {
    return page.evaluate(async () => {
        const registration = await navigator.serviceWorker.getRegistration();
        const parts = [navigator.serviceWorker.controller === null ? 'uncontrolled' : 'controlled'];
        if (registration?.installing) {
            parts.push('installing');
        }
        if (registration?.waiting) {
            parts.push('waiting');
        }
        return parts.join(', ');
    });
}

/** Token wie in tests/e2e/support/update-server.php (nur dieses gilt). */
const MAIL_TOKEN = 'Upd8-Token_aus-der-Mail_0123456789abcdefghijk';

/**
 * Seitenwechsel innerhalb der laufenden App auf eine Seite, die sie nicht kennt (wie ein Link auf eine
 * Seite, die es erst in der neuen Version gibt): „Seite nicht gefunden“ prüft zuerst.
 */
async function openUnknownPage(page: Page): Promise<void> {
    await page.evaluate(() => {
        history.pushState({}, '', 'gibt-es-erst-spaeter#code=ABCD-EFGH-JKMN');
        dispatchEvent(new PopStateEvent('popstate'));
    });
}

/**
 * Wie openUnknownPage mit installierter Uhr (page.clock): Kennt die App die neue Version schon, sucht
 * zuerst der Seitenwechsel nach ihr (Wartezeiten der App, vorgespult), dann prüft „Seite nicht
 * gefunden“ („Seite wird geladen …“).
 */
async function openUnknownPageWithClock(page: Page): Promise<void> {
    await openUnknownPage(page);
    const checking = page.getByTestId('not-found-checking');
    await expect
        .poll(
            async () => {
                if (await checking.isVisible()) {
                    return true;
                }
                await page.clock.runFor(250);
                return checking.isVisible();
            },
            { intervals: [50], timeout: 30_000 },
        )
        .toBe(true);
}

test.describe('Updates der App: Start mit veralteter Version', () => {
    test('Mail-Link mit alter Version im Service Worker: alte Version löst ihn ein, neue beim Seitenwechsel', async ({
        page,
        context,
        browserName,
        baseURL,
    }, testInfo) => {
        // Bis Schritt 1.7d lud der Start hier sofort die neue Version (die erste Seite wartete).
        // Jetzt erscheint die Seite der laufenden Version sofort und löst das Token ein: richtig,
        // weil die API nur erweitert wird (eine alte Version funktioniert weiter; lehnt der Server
        // sie ab, kommt client_outdated). Die neue Version kommt beim nächsten Seitenwechsel.
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        // Deploy, ohne dass die geöffnete App es bemerkt; dann öffnet das Mailprogramm den Link in
        // einem neuen Tab (die alte Version liegt im Service Worker). Fehler dieses Tabs prüft der
        // Test selbst (wie der Wächter in fixtures.ts, mit derselben Ausnahme für abgebrochene
        // Schrift-Downloads in Firefox); seine API-Aufrufe beantwortet der Update-Testserver.
        deploy(area);
        const mail = await context.newPage();
        const problems = collectProblems(mail, browserName, baseURL ?? '');
        await mail.goto(`/firefighting/${area}/email-bestaetigen#token=${MAIL_TOKEN}`);

        await expect(mail.getByRole('heading', { level: 1 })).toHaveText(
            'E-Mail-Adresse bestätigen',
        );
        await expect(mail.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(mail).toHaveURL(`/firefighting/${area}/email-bestaetigen`);
        await mail.getByRole('button', { name: 'E-Mail-Adresse bestätigen' }).click();
        await expect(mail.getByTestId('email-verified')).toHaveText(
            'Ihre E-Mail-Adresse ist bestätigt.',
        );
        // Inzwischen hat der Start im Hintergrund die neue Version gefunden und installiert.
        await waitForWaitingWorker(mail);
        await mail.getByRole('link', { name: 'Zur Anmeldung' }).click();
        await expect(mail.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(mail).toHaveURL(`/firefighting/${area}/anmelden`);
        await expect(mail.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
        expect(problems).toEqual([]);
        await mail.close();
    });

    test('Start mit neuer Nummer, aber ohne neuen Service Worker: Seite sofort, kein Warten', async ({
        page,
    }, testInfo) => {
        // Bis Schritt 1.7d wartete der Start hier bis rund 30 s und zeigte dann „Neu laden“. Jetzt
        // erscheint die Seite sofort (das Warten bis rund 30 s gilt nur noch vor „Seite nicht
        // gefunden“, nächster Test); das Update folgt, sobald der Browser die neue Version kennt.
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        writeFileSync(join(STATE, area), 'build-json');
        await page.reload();
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
        await expect(page.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(page.getByTestId('update-reload-needed')).toBeHidden();

        // Später ist auch sw.js neu: Die nächste Wartung findet sie, der Seitenwechsel lädt sie.
        await deployAndCheck(page, area);
        await expectNewVersionAfterNavigation(page, area);
    });

    test('„Seite nicht gefunden“ mit neuer Nummer, aber ohne neuen Service Worker: nach rund 30 s „Neu laden“', async ({
        page,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        // Deploy, bei dem nur build.json neu ist (sw.js bleibt alt): „Seite nicht gefunden“ sucht bis
        // rund 30 s (START_RETRY_DELAYS_MS) nach dem neuen Service Worker. Die Wartezeiten werden
        // vorgespult; update() und Netzwerk laufen in Echtzeit, daher in kleinen Schritten.
        writeFileSync(join(STATE, area), 'build-json');
        await page.clock.install();
        await page.reload();
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
        // Erst weiter vorspulen, wenn die Prüfung sichtbar läuft (ihre Frist ist ein Timer der App).
        await openUnknownPageWithClock(page);
        const started = await page.evaluate(() => Date.now());
        const notice = page.getByTestId('update-reload-needed');
        await expect
            .poll(
                async () => {
                    await page.clock.runFor(1000);
                    return notice.isVisible();
                },
                { intervals: [50], timeout: 30_000 },
            )
            .toBe(true);
        expect(await page.evaluate(() => Date.now())).toBeGreaterThanOrEqual(started + 30_000);
        await expect(notice).toContainText('Die neue Version konnte nicht geladen werden.');
        await expect(notice.getByRole('button', { name: 'Neu laden' })).toBeVisible();
        // Die Seite bleibt beim Laden, nie kurz „Seite nicht gefunden“.
        await expect(page.getByTestId('not-found-checking')).toBeVisible();
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeHidden();
        await expect(page.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);
    });

    test('„Neu laden“ mit zweitem alten Tab: neue Version bleibt, der andere Tab behält seine Eingabe', async ({
        page,
        context,
        browserName,
        baseURL,
    }, testInfo) => {
        // Wie live (31.10.2026): Ein zweiter Tab (Gerätedetail mit offenem „Bearbeiten“) hält den
        // alten Service Worker. „Neu laden“ meldete ihn ab, die Seite kam vom Server, aber die neue
        // Version wartete weiter (Chrome holte beim register() die alte Registrierung zurück, das
        // nächste Neuladen lieferte die alte Version). Jetzt aktiviert die Seite vom Server den
        // wartenden Service Worker; der andere Tab lädt dabei nicht neu und verliert nichts.
        testInfo.setTimeout(testInfo.timeout + 30_000);
        const area = newArea(testInfo.project.name);
        writeApiTable(area, adminDeviceApi(area));
        const other = await context.newPage();
        const problems = collectProblems(other, browserName, baseURL ?? '');
        await other.goto(`/firefighting/${area}/geraete/1/details`);
        await expect(other.getByRole('heading', { level: 1 })).toContainText('M1');
        await other.waitForFunction(() => navigator.serviceWorker.controller !== null);
        await other.getByRole('button', { name: 'Bearbeiten' }).click();
        const dialog = other.getByRole('dialog', { name: 'M1 bearbeiten' });
        await dialog.getByLabel('Seriennummer').fill('SN-neu');
        await other.evaluate(() => {
            (window as unknown as { ffMarker: boolean }).ffMarker = true;
        });
        await openOldVersion(page, area);

        // Deploy, bei dem der Browser den neuen Service Worker erst nach rund 30 s findet: „Neu laden“
        // vor „Seite nicht gefunden“ (bis Schritt 1.7d schon beim Start; dort wartet seitdem nichts).
        writeFileSync(join(STATE, area), 'build-json');
        await page.clock.install();
        await page.reload();
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
        await openUnknownPageWithClock(page);
        const reloadNeeded = page.getByTestId('update-reload-needed');
        await expect
            .poll(
                async () => {
                    await page.clock.runFor(1000);
                    return reloadNeeded.isVisible();
                },
                { intervals: [50], timeout: 30_000 },
            )
            .toBe(true);
        // Inzwischen ist der neue Service Worker installiert und wartet.
        writeFileSync(join(STATE, area), 'next');
        await page.evaluate(async () => {
            await (await navigator.serviceWorker.getRegistration())?.update();
        });
        await expect.poll(() => workerState(page)).toBe('controlled, waiting');

        await reloadNeeded.getByRole('button', { name: 'Neu laden' }).click();
        const notFound = page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' });
        await expect(page.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(notFound).toBeVisible();
        // Die neue Version übernimmt (vorher in Chromium: Seite ohne Kontrolle, neue Version wartet).
        await expect.poll(() => workerState(page)).toBe('controlled');
        await page.reload();
        await expect(page.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(notFound).toBeVisible();

        // Der andere Tab: nicht neu geladen, Eingabe da, Hinweis statt Neuladen.
        await expect(other.getByTestId('update-notice')).toContainText(
            'Neue Version verfügbar – nach dem Speichern wird aktualisiert.',
        );
        await expect(dialog.getByLabel('Seriennummer')).toHaveValue('SN-neu');
        expect(
            await other.evaluate(() => (window as unknown as { ffMarker?: boolean }).ffMarker),
        ).toBe(true);
        await expect(other.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);
        // Das Fenster beginnt unter dem Hinweis (am Handy deckte es ihn sonst zu).
        await expect
            .poll(async () => {
                const notice = await other.getByTestId('update-notice').boundingBox();
                const form = await dialog.boundingBox();
                return notice !== null && form !== null && form.y >= notice.y + notice.height;
            })
            .toBe(true);
        // Der Hinweis bleibt bei offenem Fenster bedienbar (ein Klick darauf ist kein Klick neben das
        // Fenster) und fragt wegen der Eingabe nach; „Abbrechen“ lässt Fenster und Eingabe stehen.
        // Live-Test 31.10.2026: Der Klick schloss das Fenster und verwarf die Eingabe. Der Hinweis
        // liegt hinter dem modalen Fenster (aria-hidden), daher über den Text statt die Rolle.
        await other
            .getByTestId('update-notice')
            .getByText('Jetzt aktualisieren')
            .click({ timeout: 5000 });
        const confirm = other.getByRole('alertdialog', { name: 'Jetzt aktualisieren?' });
        await expect(confirm).toContainText('Ihre Eingaben auf dieser Seite gehen verloren.');
        await confirm.getByRole('button', { name: 'Abbrechen' }).click();
        await expect(confirm).toBeHidden();
        await expect(dialog).toBeVisible();
        await expect(dialog.getByLabel('Seriennummer')).toHaveValue('SN-neu');
        expect(
            await other.evaluate(() => (window as unknown as { ffMarker?: boolean }).ffMarker),
        ).toBe(true);
        // Seitenwechsel dort: Die Dateien der alten Version gibt es nicht mehr; die App lädt die neue
        // Version mit der Zielseite, statt an einem fehlenden Teil zu scheitern.
        await dialog.getByRole('button', { name: 'Abbrechen' }).click();
        await expect(dialog).toBeHidden();
        await other.getByRole('button', { name: 'Zurück' }).click();
        await expect(other.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(other).toHaveURL(`/firefighting/${area}/geraete`);
        await expect(other.getByRole('heading', { level: 1, name: 'Ausrüstung' })).toBeVisible();
        expect(problems).toEqual([]);
        await other.close();
    });

    test('neuer Service Worker installiert langsam: „Seite nicht gefunden“ wartet und aktiviert ihn sofort danach', async ({
        page,
    }, testInfo) => {
        // Die Installation darf bis zur Frist der App dauern (siehe unten), dazu der Rest des Tests.
        testInfo.setTimeout(testInfo.timeout + START_LOAD_TIMEOUT_MS);
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        // Wie live (31.10.2026, damals beim Start; seit Schritt 1.7d wartet so nur noch „Seite nicht
        // gefunden“): Die App erkennt die neue Version, ihr Service Worker braucht für die
        // Installation aber länger als die Frist eines Seitenwechsels (ACTIVATION_TIMEOUT_MS).
        // Zuerst nur build.json neu, damit die App sucht; dann hängt die Installation, bis der Test
        // sie freigibt (update-server.php, `next-held`).
        writeFileSync(join(STATE, area), 'build-json');
        await page.clock.install();
        await page.reload();
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
        await openUnknownPageWithClock(page);
        // Frist der App für Suche und Installation (START_LOAD_TIMEOUT_MS, beginnt nach der Prüfung,
        // die höchstens START_CHECK_TIMEOUT_MS dauert). Die Uhr läuft nach clock.install() weiter
        // (Timer feuern), runFor springt zusätzlich vor.
        const deadline =
            (await page.evaluate(() => Date.now())) +
            START_LOAD_TIMEOUT_MS +
            START_CHECK_TIMEOUT_MS;
        writeFileSync(join(STATE, area), 'next-held');
        const installing = (): Promise<boolean> =>
            page.evaluate(async () => {
                const registration = await navigator.serviceWorker.getRegistration();
                return registration?.installing !== null && registration?.installing !== undefined;
            });
        // Die Suche fragt nach ihren Pausen erneut (update() in Echtzeit), bis der Browser den neuen
        // Service Worker installiert.
        await expect
            .poll(
                async () => {
                    await page.clock.runFor(500);
                    return installing();
                },
                { intervals: [50], timeout: 20_000 },
            )
            .toBe(true);
        // Mehr als die Frist eines Seitenwechsels vergeht, die Installation hängt noch.
        for (let second = 0; second < 8; second += 1) {
            await page.clock.runFor(1000);
        }
        expect(await installing()).toBe(true);
        await expect(page.getByTestId('update-reload-needed')).toBeHidden();
        await expect(page.getByTestId('not-found-checking')).toBeVisible();

        // Freigabe: Der Service Worker lädt noch alle übrigen Dateien des Builds, nacheinander
        // (Workbox, index.html ist die erste; lokal rund 1 s, in CI unter Last waren 5 s zu kurz,
        // 31.10.2026). Sobald die Installation endet, aktiviert die App die neue Version und lädt sie.
        // Der Test wartet so lange wie die App (bis zu ihrer Frist), nicht länger: Verpasste die App
        // das Ende der Installation, zeigte sie nach der Frist „Neu laden“, und der Test scheitert.
        writeFileSync(join(STATE, area), 'next');
        const remaining = deadline - (await page.evaluate(() => Date.now()));
        expect(remaining).toBeGreaterThan(0);
        await expect(page.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID, {
            timeout: remaining,
        });
        await expect(page).toHaveURL(
            `/firefighting/${area}/gibt-es-erst-spaeter#code=ABCD-EFGH-JKMN`,
        );
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeVisible();
        await expect(page.getByTestId('update-reload-needed')).toBeHidden();
    });

    test('„Seite nicht gefunden“ prüft zuerst auf eine neue Version', async ({
        page,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        deploy(area);
        // Seitenwechsel innerhalb der alten App auf eine Seite, die sie nicht kennt (die App hat seit
        // dem Deploy noch nicht geprüft).
        await openUnknownPage(page);
        await expect(page.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(page).toHaveURL(
            `/firefighting/${area}/gibt-es-erst-spaeter#code=ABCD-EFGH-JKMN`,
        );
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeVisible();
    });
});

test.describe('Updates der App: Zurück-Knopf', () => {
    test('neue Version schon erkannt, Zurück: neue Version auf der richtigen Seite', async ({
        page,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        // In der alten App weiterblättern, dann Deploy und Prüfung (wie beim Zurückkehren).
        await page.getByRole('link', { name: 'Passwort vergessen?' }).click();
        await expect(
            page.getByRole('heading', { level: 1, name: 'Passwort vergessen' }),
        ).toBeVisible();
        await deployAndCheck(page, area);

        // Zurück (popstate): Die App lädt neu; die Historie darf das Neuladen nicht überholen.
        await page.goBack();
        await expect(page.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(page).toHaveURL(`/firefighting/${area}/anmelden`);
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
    });
});

test.describe('Updates der App: Antworten der API verlangen ein Update', () => {
    /** Wie ClientApi::RESPONSE_HEADER im Backend; die laufende App kennt die Nummer 1. */
    const NEWER_API = { 'X-Api-Version': '4' };

    /**
     * Mail-Link in einem zweiten Tab (wie aus einem Mailprogramm) mit der alten Version; danach
     * Deploy, ohne dass die App es bemerkt. Antworten der API für diesen Tab aus der Tabelle des
     * Update-Testservers (alle Browser, auch WebKit unter dem Service Worker).
     */
    async function openMailLinkThenDeploy(
        page: Page,
        area: string,
        verify: MockResponse,
    ): Promise<Page> {
        await openOldVersion(page, area);
        const mail = await page.context().newPage();
        await mail.goto(`/firefighting/${area}/email-bestaetigen#token=${MAIL_TOKEN}`);
        await expect(mail.getByRole('heading', { level: 1 })).toHaveText(
            'E-Mail-Adresse bestätigen',
        );
        await expect(mail.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);
        deploy(area);
        writeApiTable(area, { 'POST api/v1/auth/email/verify': verify });
        return mail;
    }

    test('client_outdated mit offenem Link: Hinweis, Link bleibt, Aktualisieren nach Rückfrage', async ({
        page,
        browserName,
        baseURL,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        const mail = await openMailLinkThenDeploy(page, area, {
            status: 409,
            body: {
                error: 'client_outdated',
                message: 'Diese Version der App ist veraltet – bitte aktualisieren.',
                details: { min_client_api: 3 },
            },
        });
        const problems = collectProblems(mail, browserName, baseURL ?? '');
        await mail.evaluate(() => {
            (window as unknown as { ffMarker: boolean }).ffMarker = true;
        });

        const confirm = mail.getByRole('button', { name: 'E-Mail-Adresse bestätigen' });
        await confirm.click();
        // Der noch nicht eingelöste Link zählt als Eingabe: kein Neuladen, sondern der Hinweis.
        const notice = mail.getByTestId('update-notice');
        await expect(notice).toContainText('Diese Version ist veraltet – bitte aktualisieren.');
        await expect(mail.getByRole('alert')).toContainText(
            'Diese Version ist veraltet – bitte aktualisieren.',
        );
        await expect(confirm).toBeVisible();
        await expect(mail.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);
        expect(
            await mail.evaluate(() => (window as unknown as { ffMarker?: boolean }).ffMarker),
        ).toBe(true);

        await notice.getByRole('button', { name: 'Jetzt aktualisieren' }).click();
        const dialog = mail.getByRole('alertdialog', { name: 'Jetzt aktualisieren?' });
        await dialog.getByRole('button', { name: 'Aktualisieren' }).click();
        await expect(mail.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(mail).toHaveURL(`/firefighting/${area}/email-bestaetigen`);
        await expect(mail.getByTestId('update-notice')).toBeHidden();
        // Erwartet nur die Meldung des Browsers zur Antwort 409.
        expect(problems.filter((problem) => !HTTP_ERROR_CONSOLE.test(problem))).toEqual([]);
        await mail.close();
    });

    test('neuere X-Api-Version: sofort geprüft, beim nächsten Seitenwechsel die neue Version', async ({
        page,
        browserName,
        baseURL,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        const mail = await openMailLinkThenDeploy(page, area, {
            status: 200,
            body: { message: 'Ihre E-Mail-Adresse ist bestätigt.' },
            headers: NEWER_API,
        });
        const problems = collectProblems(mail, browserName, baseURL ?? '');

        await mail.getByRole('button', { name: 'E-Mail-Adresse bestätigen' }).click();
        await expect(mail.getByTestId('email-verified')).toHaveText(
            'Ihre E-Mail-Adresse ist bestätigt.',
        );
        // Ohne Eingabe kein Hinweis; die App prüft sofort, die neue Version wartet installiert.
        await expect
            .poll(() =>
                mail.evaluate(async () => {
                    const registration = await navigator.serviceWorker.getRegistration();
                    return registration?.waiting?.state ?? null;
                }),
            )
            .toBe('installed');
        await expect(mail.getByTestId('update-notice')).toBeHidden();
        await expect(mail.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);

        await mail.getByRole('link', { name: 'Zur Anmeldung' }).first().click();
        await expect(mail.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(mail).toHaveURL(`/firefighting/${area}/anmelden`);
        expect(problems).toEqual([]);
        await mail.close();
    });
});
