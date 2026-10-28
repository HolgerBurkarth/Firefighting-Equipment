import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Page } from '@playwright/test';
import { expect, test } from './fixtures';
import { NEXT_BUILD_ID } from './globalSetup';

/**
 * Updates mit zwei echten Builds: Der Update-Testserver (Port 4174, tests/e2e/support/
 * update-server.php) liefert je Bereich frontend/dist (alt) oder test-builds/next (neu). Jeder Test
 * hat einen eigenen Bereich und damit einen eigenen Service Worker.
 */
const ORIGIN = 'http://localhost:4174';
const STATE = fileURLToPath(new URL('../../test-builds/state/', import.meta.url));

test.use({ serviceWorkers: 'allow' });

let areas = 0;

function newArea(project: string): string {
    areas += 1;
    return `upd-${project}-${process.pid}-${areas}`;
}

/** Alte Version laden und warten, bis ihr Service Worker die Seite kontrolliert. */
async function openOldVersion(page: Page, area: string): Promise<void> {
    await page.goto(`${ORIGIN}/firefighting/${area}/anmelden`);
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
        await expect(page).toHaveURL(`${ORIGIN}/firefighting/${area}/passwort-vergessen`);
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
        await expect(page).toHaveURL(`${ORIGIN}/firefighting/${area}/anmelden`);
        await expect(page.getByTestId('update-notice')).toBeHidden();
    });
});
