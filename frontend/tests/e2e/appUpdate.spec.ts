import { randomUUID } from 'node:crypto';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Page } from '@playwright/test';
import { expect, test as base } from './fixtures';
import { NEXT_BUILD_ID } from './globalSetup';

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
 * Firefox meldet einen Schrift-Download, den das Neuladen abbricht, als Konsolenfehler (Status
 * 2152398850 = NS_BINDING_ABORTED). Nur dieser Fall ist erwartet; andere Schriftfehler (z. B. 404)
 * haben einen anderen Status und lassen den Test weiter scheitern.
 */
const FONT_ABORTED_BY_RELOAD = /downloadable font: download failed .*status=2152398850 /;

/** Token wie in tests/e2e/support/update-server.php (nur dieses gilt). */
const MAIL_TOKEN = 'Upd8-Token_aus-der-Mail_0123456789abcdefghijk';

test.describe('Updates der App: Start mit veralteter Version', () => {
    test('Mail-Link mit alter Version im Service Worker: neue Version lädt, Token bleibt', async ({
        page,
        context,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        // Deploy, ohne dass die geöffnete App es bemerkt; dann öffnet das Mailprogramm den Link in
        // einem neuen Tab (die alte Version liegt im Service Worker). Fehler dieses Tabs prüft der
        // Test selbst; seine API-Aufrufe beantwortet der Update-Testserver.
        deploy(area);
        const mail = await context.newPage();
        const problems: string[] = [];
        mail.on('pageerror', (error) => problems.push(`Skriptfehler: ${error.message}`));
        mail.on('console', (message) => {
            if (message.type() === 'error' && !FONT_ABORTED_BY_RELOAD.test(message.text())) {
                problems.push(`Konsolenfehler: ${message.text()}`);
            }
        });
        await mail.goto(`/firefighting/${area}/email-bestaetigen#token=${MAIL_TOKEN}`);

        await expect(mail.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(mail.getByRole('heading', { level: 1 })).toHaveText(
            'E-Mail-Adresse bestätigen',
        );
        // Das Fragment entfernt erst die neue Version.
        await expect(mail).toHaveURL(`/firefighting/${area}/email-bestaetigen`);
        await mail.getByRole('button', { name: 'E-Mail-Adresse bestätigen' }).click();
        await expect(mail.getByTestId('email-verified')).toHaveText(
            'Ihre E-Mail-Adresse ist bestätigt.',
        );
        expect(problems).toEqual([]);
        await mail.close();
    });

    test('neue Kennung, aber kein neuer Service Worker: nach rund 30 s „Neu laden“', async ({
        page,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        // Deploy, bei dem nur build.json neu ist (sw.js bleibt alt): Der Start sucht bis rund 30 s
        // (START_RETRY_DELAYS_MS) nach dem neuen Service Worker. Die Wartezeiten werden vorgespult;
        // update() und Netzwerk laufen in Echtzeit, daher in kleinen Schritten.
        writeFileSync(join(STATE, area), 'build-json');
        await page.clock.install();
        await page.reload();
        // Erst vorspulen, wenn der Start die neue Kennung erkannt hat und sucht: Das Abwarten von
        // build.json (START_CHECK_TIMEOUT_MS) ist ein Timer der App; vorgespult liefe er unter Last
        // ab, bevor die echte Antwort da ist, und die alte Anmeldeseite erschiene.
        await expect(page.getByTestId('update-loading')).toBeVisible();
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
        // Die Seite bleibt beim Laden, nie kurz die alte Anmeldeseite.
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeHidden();
        await expect(page.locator('html')).not.toHaveAttribute('data-build', NEXT_BUILD_ID);
    });

    test('neuer Service Worker installiert langsam: Start wartet und aktiviert ihn sofort danach', async ({
        page,
    }, testInfo) => {
        const area = newArea(testInfo.project.name);
        await openOldVersion(page, area);
        // Wie live (31.10.2026): Der Start erkennt die neue Version, ihr Service Worker braucht für
        // die Installation aber länger als die Frist eines Seitenwechsels (ACTIVATION_TIMEOUT_MS).
        // Zuerst nur build.json neu, damit der Start sucht; dann hängt die Installation, bis der
        // Test sie freigibt (update-server.php, `next-held`).
        writeFileSync(join(STATE, area), 'build-json');
        await page.clock.install();
        await page.reload();
        await expect(page.getByTestId('update-loading')).toBeVisible();
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
        await expect(page.getByTestId('update-loading')).toBeVisible();

        // Installation fertig: Die App aktiviert die neue Version sofort und lädt sie.
        writeFileSync(join(STATE, area), 'next');
        await expect(page.locator('html')).toHaveAttribute('data-build', NEXT_BUILD_ID);
        await expect(page).toHaveURL(`/firefighting/${area}/anmelden`);
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
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
        await page.evaluate(() => {
            history.pushState({}, '', 'gibt-es-erst-spaeter#code=ABCD-EFGH-JKMN');
            dispatchEvent(new PopStateEvent('popstate'));
        });
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
