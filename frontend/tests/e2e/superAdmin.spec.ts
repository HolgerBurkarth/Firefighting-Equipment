import type { Locator, Page } from '@playwright/test';
import { DATABASE_UNAVAILABLE, expect, HTTP_ERROR_CONSOLE, meBody, test } from './fixtures';
import { horizontalOverflow } from './support/layout';
import { CURRENT_SESSION_ONLY } from './support/profile';
import { accountJson, SERVICE_OVERVIEW, TENANTS, tenantJson } from './support/service';

/**
 * Bereich des Super-Admins unter `service` (KONZEPT.md, "Super-Admin – Entscheidungen"): Menü wie
 * die Admin-Konsole, Dashboard mit Kacheln, offenen Einladungen und Städten, Städte-Liste,
 * Einstellungen. Konten einer Stadt sehen keine dieser Seiten.
 */

const ME = { status: 200, body: meBody('superadmin', 'Holger') } as const;

function menu(page: Page): Locator {
    return page.getByRole('navigation', { name: 'Hauptmenü' });
}

test.describe('Super-Admin', () => {
    test.use({ api: { 'GET api/v1/auth/me': ME, ...SERVICE_OVERVIEW } });

    test('PC: Menü mit Dashboard und Städten, oben „Service“', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/service/dashboard');
        const nav = menu(page);
        await expect(nav.getByTestId('menu-tenant')).toHaveText('Service');
        await expect(nav.getByText('Super-Admin', { exact: true })).toBeVisible();
        await expect(nav.getByRole('link')).toHaveText(['Dashboard', 'Städte', 'Einstellungen']);
        await expect(nav.getByRole('link', { name: 'Dashboard' })).toHaveClass(/menu-link-active/);
        await expect(nav.getByTestId('account-name')).toHaveText('Holger');
        await expect(nav.getByRole('button', { name: 'Abmelden' })).toBeVisible();
        // Keine Geräte: kein Suchen, keine Leiste unten.
        await expect(page.locator('.tab-bar')).toHaveCount(0);
        await expect(nav.getByRole('link', { name: 'Suchen' })).toHaveCount(0);
    });

    test('Dashboard: Kacheln, offene Einladung und Städte', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/service/dashboard');
        await expect(page.getByTestId('tile-tenants')).toContainText('3 aktiv');
        await expect(page.getByTestId('tile-tenants')).toContainText('0 gesperrt');
        await expect(page.getByTestId('tile-invitations')).toContainText('1');
        await expect(page.getByTestId('tile-invitations')).toContainText('24 Stunden');
        await expect(page.getByTestId('tile-users')).toContainText('37');

        const invitation = page.getByTestId('invitation-31');
        await expect(invitation).toContainText('kommandant@bad-duerrheim.de');
        await expect(invitation).toContainText(
            'Feuerwehr Bad Dürrheim · eingeladen seit 20.10.2026',
        );

        const table = page.getByTestId('tenant-table');
        await expect(table.locator('tbody tr')).toHaveCount(3);
        const duerrheim = page.getByTestId('tenant-bad-duerrheim');
        await expect(duerrheim).toContainText('/firefighting/bad-duerrheim');
        await expect(duerrheim).toContainText('Einladung offen');
        await expect(duerrheim).toContainText('(1 eingeladen)');
        await expect(page.getByTestId('tenant-immendingen')).toContainText('aktiv');
        // Kein Link in eine Stadt (Entscheidung 47).
        await expect(table.locator('a')).toHaveCount(0);

        await page.getByTestId('tile-tenants').click();
        await expect(page).toHaveURL(/\/firefighting\/service\/staedte$/);
        await expect(menu(page).getByRole('link', { name: 'Städte' })).toHaveClass(
            /menu-link-active/,
        );
    });

    test.describe('Einladung erneut senden', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': ME,
                ...SERVICE_OVERVIEW,
                'POST api/v1/tenants/3/users/31/resend-invitation': {
                    status: 200,
                    body: {
                        user: accountJson(31, { email: 'kommandant@bad-duerrheim.de' }),
                        mail_sent: true,
                    },
                },
            },
        });

        test('sendet und meldet den Erfolg', async ({ page, apiRequests }) => {
            await page.goto('/firefighting/service/dashboard');
            await page
                .getByRole('button', {
                    name: 'Einladung an kommandant@bad-duerrheim.de erneut senden',
                })
                .click();
            await expect(page.getByTestId('success-notice')).toHaveText(
                'Neue Einladung an kommandant@bad-duerrheim.de gesendet (Feuerwehr Bad Dürrheim). Der Link gilt 24 Stunden.',
            );
            const sent = apiRequests.filter((request) => request.method === 'POST');
            expect(sent.map((request) => request.path)).toEqual([
                'api/v1/tenants/3/users/31/resend-invitation',
            ]);
            expect(sent[0]?.body).toEqual({});
        });
    });

    test.describe('Mail nicht gesendet', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': ME,
                ...SERVICE_OVERVIEW,
                'POST api/v1/tenants/3/users/31/resend-invitation': {
                    status: 200,
                    body: {
                        user: accountJson(31, { email: 'kommandant@bad-duerrheim.de' }),
                        mail_sent: false,
                    },
                },
            },
        });

        test('sagt es deutlich', async ({ page }) => {
            await page.goto('/firefighting/service/dashboard');
            await page.getByRole('button', { name: /erneut senden$/ }).click();
            await expect(page.getByTestId('success-notice')).toContainText(
                'konnte nicht gesendet werden',
            );
        });
    });

    test('Städte: Suche und Sortierung über die Spaltenköpfe', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/service/staedte');
        await expect(page.getByRole('heading', { level: 1, name: 'Städte' })).toBeVisible();
        await expect(page.getByTestId('tenant-count')).toHaveText('3 Städte');
        const names = page.locator('tbody .tenant-name');
        await expect(names).toHaveText([
            'Feuerwehr Bad Dürrheim',
            'Feuerwehr Immendingen',
            'Test-Feuerwehr',
        ]);
        const usersHeader = page.getByRole('columnheader', { name: 'Benutzer' });
        await usersHeader.getByRole('button').click();
        await expect(usersHeader).toHaveAttribute('aria-sort', 'ascending');
        await expect(names).toHaveText([
            'Feuerwehr Bad Dürrheim',
            'Test-Feuerwehr',
            'Feuerwehr Immendingen',
        ]);
        await usersHeader.getByRole('button').click();
        await expect(usersHeader).toHaveAttribute('aria-sort', 'descending');
        await expect(names.first()).toHaveText('Feuerwehr Immendingen');

        await page.getByRole('searchbox', { name: 'Städte filtern' }).fill('durrh');
        await expect(names).toHaveText(['Feuerwehr Bad Dürrheim']);
        await page.getByRole('searchbox', { name: 'Städte filtern' }).fill('xyz');
        await expect(page.getByText('Keine Stadt passt zu „xyz“.')).toBeVisible();
    });

    test('Handy: Karten mit Sortier-Knöpfen, Menü in der Schublade', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/firefighting/service/staedte');
        await expect(page.locator('thead')).toBeHidden();
        const card = page.getByTestId('tenant-test');
        await expect(card).toContainText('Benutzer: 13');
        await expect(card).toContainText('Admins: 2');
        const sorting = page.getByRole('group', { name: 'Sortieren' });
        await sorting.getByRole('button', { name: /^Benutzer/ }).click();
        await expect(sorting.getByRole('button', { name: /^Benutzer/ })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expect(page.locator('tbody .tenant-name').first()).toHaveText(
            'Feuerwehr Bad Dürrheim',
        );
        expect(await horizontalOverflow(page)).toBe(0);

        await page.getByRole('button', { name: 'Menü öffnen' }).click();
        const drawer = page.getByTestId('menu-drawer');
        await expect(drawer.getByRole('link')).toHaveText(['Dashboard', 'Städte', 'Einstellungen']);
        await drawer.getByRole('link', { name: 'Dashboard' }).click();
        await expect(page).toHaveURL(/\/firefighting\/service\/dashboard$/);
        await expect(drawer).toBeHidden();
        expect(await horizontalOverflow(page)).toBe(0);
    });

    test.describe('gesperrte Stadt', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': ME,
                'GET api/v1/tenants': {
                    status: 200,
                    body: { tenants: [...TENANTS.slice(0, 2), tenantJson(4, { active: false })] },
                },
            },
        });

        test('zählt in der Kachel und ist als gesperrt markiert', async ({ page }) => {
            await page.goto('/firefighting/service/dashboard');
            await expect(page.getByTestId('tile-tenants')).toContainText('2 aktiv');
            await expect(page.getByTestId('tile-tenants')).toContainText('1 gesperrt');
            await expect(page.getByTestId('tenant-stadt-4')).toContainText('gesperrt');
            // Ohne offene Einladung kein Abschnitt dafür.
            await expect(page.getByTestId('open-invitations')).toHaveCount(0);
        });
    });

    test.describe('Datenbank gestört', () => {
        let failing = true;
        test.use({
            api: {
                'GET api/v1/auth/me': ME,
                'GET api/v1/tenants': () =>
                    failing ? DATABASE_UNAVAILABLE : { status: 200, body: { tenants: TENANTS } },
                'GET api/v1/tenants/3/users': {
                    status: 200,
                    body: { users: [accountJson(31)] },
                },
            },
            allowedConsoleError: HTTP_ERROR_CONSOLE,
        });

        test('Fehler mit „Erneut versuchen“', async ({ page }) => {
            failing = true;
            await page.goto('/firefighting/service/dashboard');
            await expect(page.getByRole('alert')).toContainText('nicht erreichbar');
            failing = false;
            await page.getByRole('button', { name: 'Erneut versuchen' }).click();
            await expect(page.getByTestId('tile-users')).toContainText('37');
        });
    });

    test.describe('Einstellungen', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': ME,
                ...CURRENT_SESSION_ONLY,
                'GET api/v1/auth/notifications': {
                    status: 200,
                    body: { notifications: { device_log: false } },
                },
            },
        });

        test('Anzeigemodus, Benachrichtigung, Passwort, Geräte; kein Erscheinungsbild', async ({
            page,
        }) => {
            await page.setViewportSize({ width: 1280, height: 800 });
            await page.goto('/firefighting/service/einstellungen');
            await expect(
                page.getByRole('heading', { level: 1, name: 'Einstellungen' }),
            ).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Anzeigemodus' })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Benachrichtigung' })).toBeVisible();
            await expect(page.getByText('aus allen Städten', { exact: false })).toBeVisible();
            await expect(page.getByTestId('device-log')).not.toBeChecked();
            await expect(page.getByRole('heading', { name: 'Passwort' })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Angemeldete Geräte' })).toBeVisible();
            await expect(page.getByText('Erscheinungsbild')).toHaveCount(0);
            await expect(menu(page).getByRole('link', { name: 'Einstellungen' })).toHaveClass(
                /menu-link-active/,
            );
        });
    });
});

test.describe('Konto einer Stadt', () => {
    test.use({ api: { 'GET api/v1/auth/me': { status: 200, body: meBody('admin') } } });

    test('sieht keine Seite des Super-Admins', async ({ page }) => {
        await page.goto('/firefighting/test/staedte');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeVisible();
    });
});
