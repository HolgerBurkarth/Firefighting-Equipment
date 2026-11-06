import type { Locator, Page } from '@playwright/test';
import { expect, meBody, test, TEST_TENANT, type MockRequest } from './fixtures';
import { ADMIN_OVERVIEW } from './support/admin';
import { NO_DEVICES } from './support/devices';
import { CURRENT_SESSION_ONLY } from './support/profile';
import { SERVICE_OVERVIEW } from './support/service';

/**
 * „Konto wechseln“ (Konzept E14, Plan Phase 1, Schritt 1.9): Liste der Konten dieses Geräts in
 * mehreren Städten. Zwei Städte im selben Testserver: `test` (Feuerwehr Test) und `hattingen`
 * (FF Hattingen); die API antwortet je Bereich (`request.area`).
 */

const HATTINGEN = { slug: 'hattingen', name: 'FF Hattingen' };

function tenantOf(request: MockRequest) {
    return request.area === 'hattingen'
        ? {
              status: 200,
              body: {
                  ...TEST_TENANT.body,
                  ...HATTINGEN,
                  accent_color: '#1d3557',
                  accent_color_is_default: false,
              },
          }
        : TEST_TENANT;
}

function meOf(role: 'admin' | 'user') {
    return (request: MockRequest) => {
        const body = meBody(role);
        return {
            status: 200,
            body:
                request.area === 'hattingen'
                    ? {
                          ...body,
                          user: { ...(body['user'] as object), id: 9, role_name: 'Gerätewart' },
                          tenant: HATTINGEN,
                      }
                    : body,
        };
    };
}

/** Liste mit Hattingen (angemeldet: Sitzung gespeichert) und Tuttlingen (abgelaufen). */
async function rememberOtherCities(page: Page): Promise<void> {
    await page.addInitScript(() => {
        if (localStorage.getItem('ff-accounts') !== null) {
            return;
        }
        localStorage.setItem(
            'ff-accounts',
            JSON.stringify({
                format: 1,
                accounts: [
                    {
                        slug: 'hattingen',
                        tenantName: 'FF Hattingen',
                        accountId: 9,
                        displayName: 'Anna',
                        roleName: 'Gerätewart',
                    },
                    {
                        slug: 'tuttlingen',
                        tenantName: 'FF Tuttlingen',
                        accountId: 11,
                        displayName: 'A. Beispiel',
                        roleName: 'Benutzer',
                    },
                ],
            }),
        );
        localStorage.setItem(
            'ff-session:hattingen',
            JSON.stringify({
                user: { id: 9, email: null, name: 'Anna', display_name: 'Anna', role: 'user' },
                tenant: { slug: 'hattingen', name: 'FF Hattingen' },
            }),
        );
        localStorage.setItem(
            'ff-tenant:hattingen',
            JSON.stringify({ name: 'FF Hattingen', accent_color: '#1d3557' }),
        );
    });
}

function accounts(page: Page): Promise<unknown> {
    return page.evaluate(() => {
        const stored = JSON.parse(localStorage.getItem('ff-accounts') ?? 'null') as {
            accounts: { slug: string }[];
        } | null;
        return stored?.accounts.map((entry) => entry.slug) ?? null;
    });
}

function menu(page: Page): Locator {
    return page.getByRole('navigation', { name: 'Hauptmenü' });
}

test.describe('Konto wechseln in der Admin-Konsole', () => {
    test.use({
        api: {
            'GET api/v1/tenant': tenantOf,
            'GET api/v1/auth/me': meOf('admin'),
            ...ADMIN_OVERVIEW,
        },
    });

    test('PC: Name im Menü öffnet die Liste, Wechsel lädt die andere Stadt', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await rememberOtherCities(page);
        await page.goto('/firefighting/test/dashboard');

        const trigger = menu(page).getByRole('button', { name: /Anna/ });
        await expect(trigger).toContainText('Feuerwehr Test · Admin');
        await trigger.click();
        const popover = page.getByTestId('account-switch');
        await expect(popover.getByRole('heading', { name: 'Konto wechseln' })).toBeVisible();
        // Eigener Hintergrund (live 06.11.2026: ohne war das Popover durchsichtig).
        await expect(popover).not.toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
        await expect(popover).toHaveCSS('z-index', '50');
        const list = popover.getByTestId('account-list');
        await expect(list.getByRole('button')).toHaveText([
            /Feuerwehr Test\s*Anna · Admin\s*aktuell/,
            /FF Hattingen\s*Anna · Gerätewart\s*angemeldet/,
            /FF Tuttlingen\s*A\. Beispiel · Benutzer\s*neu anmelden/,
        ]);
        await expect(popover.getByTestId('account-test')).toHaveAttribute('aria-current', 'true');
        await expect(
            popover.getByText('Anmeldung abgelaufen – beim Antippen öffnet sich'),
        ).toBeVisible();
        expect(await accounts(page)).toEqual(['test', 'hattingen', 'tuttlingen']);

        await popover.getByTestId('account-hattingen').click();
        await expect(page).toHaveURL(/\/firefighting\/hattingen\/dashboard$/);
        await expect(menu(page).getByTestId('menu-tenant')).toHaveText('FF Hattingen');
        await expect(menu(page).getByRole('button', { name: /Anna/ })).toContainText(
            'FF Hattingen · Gerätewart',
        );

        // In Hattingen ist Test jetzt „angemeldet“ (Sitzung von eben gespeichert).
        await menu(page).getByRole('button', { name: /Anna/ }).click();
        await expect(page.getByTestId('account-test')).toContainText('angemeldet');
        await expect(page.getByTestId('account-hattingen')).toContainText('aktuell');
    });

    test('abgelaufene Anmeldung führt zur Anmeldung der anderen Stadt', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await rememberOtherCities(page);
        await page.goto('/firefighting/test/dashboard');
        await menu(page).getByRole('button', { name: /Anna/ }).click();
        await page.getByTestId('account-tuttlingen').click();

        await expect(page).toHaveURL(/\/firefighting\/tuttlingen\/anmelden$/);
        await expect(page.getByLabel('E-Mail')).toBeVisible();
    });

    test('Tablet (Menü auf Symbolen) und Schublade am Handy', async ({ page }) => {
        await rememberOtherCities(page);
        await page.setViewportSize({ width: 900, height: 800 });
        await page.goto('/firefighting/test/dashboard');
        await menu(page).getByRole('button', { name: /Anna/ }).click();
        await expect(page.getByTestId('account-hattingen')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.getByTestId('account-switch')).toBeHidden();

        await page.setViewportSize({ width: 390, height: 844 });
        await page.getByRole('button', { name: 'Menü öffnen' }).click();
        const drawer = page.getByRole('dialog', { name: 'Menü' });
        const trigger = drawer.getByRole('button', { name: /^Anna Feuerwehr Test/ });
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');
        await trigger.click();
        await expect(trigger).toHaveAttribute('aria-expanded', 'true');
        await expect(drawer.getByTestId('account-hattingen')).toBeVisible();
        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.body.clientWidth,
        );
        expect(overflow).toBeLessThanOrEqual(0);
    });
});

test.describe('Konto wechseln im Profil', () => {
    test.use({
        api: {
            'GET api/v1/tenant': tenantOf,
            'GET api/v1/auth/me': meOf('user'),
            'POST api/v1/auth/logout': { status: 200, body: { logged_out: true } },
            ...NO_DEVICES,
            ...CURRENT_SESSION_ONLY,
        },
    });

    test.beforeEach(async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await rememberOtherCities(page);
    });

    test('Liste am Handy mit „wechseln“; ungespeicherte Eingaben erst nach Rückfrage', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/profil');
        const section = page.getByTestId('account-switch');
        await expect(section.getByRole('heading', { name: 'Konto wechseln' })).toBeVisible();
        await expect(section.getByTestId('account-hattingen')).toContainText('wechseln');
        await expect(section.getByTestId('account-test')).toContainText('aktuell');

        await page.getByRole('button', { name: 'Passwort ändern' }).click();
        await page.locator('#current-password').fill('etwas');
        await section.getByTestId('account-hattingen').click();
        const confirm = page.getByTestId('confirm-dialog');
        await expect(confirm).toContainText(
            'Ihre Eingaben auf dieser Seite gehen verloren. Trotzdem wechseln?',
        );
        await expect(confirm.getByRole('button', { name: 'Abbrechen' })).toBeFocused();
        await confirm.getByRole('button', { name: 'Abbrechen' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/profil$/);
        await expect(page.locator('#current-password')).toHaveValue('etwas');

        await section.getByTestId('account-hattingen').click();
        await page.getByTestId('confirm-dialog').getByRole('button', { name: 'Wechseln' }).click();
        await expect(page).toHaveURL(/\/firefighting\/hattingen\/suchen$/);
    });

    test('Weitere Stadt hinzufügen: Prüfung wie das Kürzel, dann Anmeldung dieser Stadt', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/profil');
        const section = page.getByTestId('account-switch');
        await section.getByRole('button', { name: '+ Weitere Stadt hinzufügen …' }).click();
        const input = section.getByLabel('Adresse oder Kürzel der Stadt');
        await expect(input).toBeFocused();

        await input.fill('service');
        await section.getByRole('button', { name: 'Zur Anmeldung' }).click();
        await expect(input).toHaveAttribute('aria-invalid', 'true');
        await input.fill('test');
        await section.getByRole('button', { name: 'Zur Anmeldung' }).click();
        await expect(
            section.getByText('In dieser Stadt sind Sie gerade angemeldet.'),
        ).toBeVisible();

        await input.fill('www.prodad.de/firefighting/geisingen/');
        await input.press('Enter');
        await expect(page).toHaveURL(/\/firefighting\/geisingen\/anmelden$/);
    });

    test('Abmelden entfernt nur den Eintrag dieser Stadt', async ({ page }) => {
        await page.goto('/firefighting/test/profil');
        await expect.poll(() => accounts(page)).toEqual(['test', 'hattingen', 'tuttlingen']);

        await page.getByRole('button', { name: 'Abmelden' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/anmelden$/);
        await expect.poll(() => accounts(page)).toEqual(['hattingen', 'tuttlingen']);
        expect(
            await page.evaluate(() => localStorage.getItem('ff-session:hattingen')),
        ).not.toBeNull();
    });
});

test.describe('Super-Admin', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': { status: 200, body: meBody('superadmin') },
            ...SERVICE_OVERVIEW,
        },
    });

    test('unter service keine Liste und kein Eintrag', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/service/dashboard');
        await expect(menu(page).getByTestId('account-name')).toBeVisible();
        await expect(menu(page).getByRole('button', { name: /Anna/ })).toHaveCount(0);
        expect(await page.evaluate(() => localStorage.getItem('ff-accounts'))).toBeNull();
    });
});
