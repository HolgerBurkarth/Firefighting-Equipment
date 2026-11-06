import type { Page } from '@playwright/test';
import { expect, HTTP_ERROR_CONSOLE, meBody, test } from './fixtures';
import { horizontalOverflow } from './support/layout';
import { delegate } from './support/masterData';
import { roleServer, type RoleServer } from './support/roles';
import { serviceServer, type ServiceServer } from './support/service';

/**
 * Rollen einer Stadt durch den Super-Admin (Plan Phase 1, Schritt 1.5): Reiter „Rollen“ im
 * Stadtdetail, „Rolle bearbeiten“ (Bereiche, Aktionen, „Sieht nur …“, F3, Versionskonflikt),
 * Kopieren, Übernehmen, Löschen, Admin nur ansehen. Attrappen `serviceServer` und `roleServer` je
 * Test neu.
 */

const ME = { status: 200, body: meBody('superadmin', 'Holger') } as const;

let service: ServiceServer = serviceServer();
let roles: RoleServer = roleServer();

test.use({
    api: {
        'GET api/v1/auth/me': ME,
        ...delegate(() => ({ ...service.handlers, ...roles.handlers })),
    },
});

test.beforeEach(() => {
    service = serviceServer();
    roles = roleServer();
});

function posts(requests: readonly { method: string; path: string; body: unknown }[]): unknown[] {
    return requests
        .filter((request) => request.method !== 'GET')
        .map((request) => [request.method, request.path, request.body]);
}

function areaButton(page: Page, area: string, level: string) {
    return page.getByTestId(`area-${area}`).getByRole('button', { name: level, exact: true });
}

test('Reiter „Rollen“: Admin, Standard-Rolle und eigene Rollen', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/firefighting/service/staedte/2');
    await page.getByRole('tab', { name: 'Rollen' }).click();
    await expect(page).toHaveURL(/staedte\/2\?ansicht=rollen$/);

    const admin = page.getByTestId('role-1');
    await expect(admin).toContainText('fest');
    await expect(admin).toContainText('alles');
    await expect(admin.getByRole('link', { name: 'Ansehen: Admin' })).toBeVisible();
    await expect(admin.getByRole('button')).toHaveCount(0);

    const user = page.getByTestId('role-2');
    await expect(user).toContainText('Standard');
    await expect(user).toContainText('Suchen, Termine, Profil');
    await expect(user).toContainText('Umbuchen, Status setzen');
    await expect(user.getByRole('button', { name: /löschen/ })).toHaveCount(0);

    // Mit Personen kein „Löschen …“, ohne Nutzung schon.
    await expect(page.getByTestId('role-3')).toContainText('Gerätewart');
    await expect(page.getByTestId('role-3').getByRole('button', { name: /löschen/ })).toHaveCount(
        0,
    );
    await expect(page.getByTestId('role-4')).toContainText('1 Standort, alle Ausrüstungstypen');
    await expect(page.getByRole('button', { name: 'Live-Test leer löschen …' })).toBeVisible();

    await page.getByRole('tab', { name: 'Admins und Benutzer' }).click();
    await expect(page).toHaveURL(/staedte\/2\?ansicht=konten$/);
    await expect(page.getByTestId('account-21')).toBeVisible();
    await page.getByRole('tab', { name: 'Übersicht' }).click();
    await expect(page).toHaveURL(/staedte\/2$/);
    await expect(page.getByTestId('tenant-facts')).toBeVisible();
});

test('neue Rolle: Bereiche, Aktionen, „Sieht nur …“, zurück mit Bestätigung', async ({
    page,
    apiRequests,
}) => {
    await page.goto('/firefighting/service/staedte/2?ansicht=rollen');
    await page.getByRole('link', { name: 'Neue Rolle' }).click();
    await expect(page).toHaveURL(/staedte\/2\/rollen\/neu$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Neue Rolle' })).toBeVisible();

    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.getByText('Bitte einen Namen eingeben.')).toBeVisible();
    await expect(page.getByTestId('save-problem')).toBeVisible();

    await page.getByLabel('Name der Rolle').fill('Live-Test Träger');
    const move = page.getByRole('checkbox', { name: /Umbuchen/ });
    await expect(move).toBeDisabled();
    await expect(
        page.getByText('braucht „Suchen und Ausrüstung ansehen: Ansehen“').first(),
    ).toBeVisible();
    await areaButton(page, 'search', 'Ansehen').click();
    await expect(areaButton(page, 'search', 'Ansehen')).toHaveAttribute('aria-pressed', 'true');
    await expect(move).toBeEnabled();
    await move.check();
    const preview = page.getByTestId('role-preview');
    await expect(preview).toContainText('Keine Konsole – nur die Handy-Seiten.');
    await expect(preview).toContainText('Suchen · Profil');
    await expect(preview).toContainText('Umbuchen');

    const locations = page.getByTestId('filter-locations');
    await locations.getByLabel('Nur ausgewählte').check();
    await expect(page.getByTestId('locations-problem')).toHaveText(
        'Bitte mindestens einen Standort wählen oder „Alle Standorte“.',
    );
    await expect(locations).toContainText('Lager (gesperrt)');
    await locations.getByRole('checkbox', { name: 'G1' }).check();
    await expect(page.getByTestId('locations-problem')).toBeHidden();
    await expect(preview).toContainText('1 von 3 Standorten, alle Ausrüstungstypen');

    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page).toHaveURL(/staedte\/2\?ansicht=rollen$/);
    await expect(page.getByTestId('roles-notice')).toHaveText('Rolle „Live-Test Träger“ angelegt.');
    await expect(page.getByTestId('role-100')).toContainText('Live-Test Träger');
    expect(posts(apiRequests)).toEqual([
        [
            'POST',
            'api/v1/tenants/2/roles',
            {
                name: 'Live-Test Träger',
                description: null,
                areas: { search: 'read' },
                actions: ['book.move'],
                locations: [11],
                device_types: null,
            },
        ],
    ]);
});

test('F3: mit „Sieht nur …“ keine Verwaltung', async ({ page, apiRequests }) => {
    await page.goto('/firefighting/service/staedte/2/rollen/3');
    await expect(page.getByLabel('Name der Rolle')).toHaveValue('Gerätewart');
    await expect(page.getByTestId('role-impact')).toContainText('2 Personen');
    await areaButton(page, 'users', 'Ansehen').click();

    const types = page.getByTestId('filter-types');
    await types.getByLabel('Nur ausgewählte').check();
    await types.getByRole('checkbox', { name: 'Atemschutzmaske' }).check();
    await expect(types).toContainText('Funkgerät (deaktiviert)');
    await expect(page.getByTestId('filter-conflicts')).toContainText(
        'Mit „Sieht nur …“ darf die Rolle nicht verwalten: „Benutzer: Ansehen“.',
    );
    await expect(areaButton(page, 'users', 'Ändern')).toBeDisabled();
    await expect(page.getByTestId('area-users')).toContainText('Mit „Sieht nur …“ kein Zugriff.');
    await expect(page.getByRole('checkbox', { name: /CSV-Import/ })).toBeDisabled();

    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.getByTestId('save-problem')).toBeVisible();
    expect(posts(apiRequests)).toEqual([]);

    await areaButton(page, 'users', 'Kein Zugriff').click();
    await expect(page.getByTestId('filter-conflicts')).toBeHidden();
    await page.getByRole('button', { name: 'Speichern' }).click();
    // Direkt geöffnet: danach die Rollen der Stadt statt der vorigen Seite.
    await expect(page).toHaveURL(/staedte\/2\?ansicht=rollen$/);
    await expect(page.getByTestId('roles-notice')).toHaveText('Rolle „Gerätewart“ gespeichert.');
    await expect(page.getByTestId('role-3')).toContainText('alle Standorte, 1 Ausrüstungstyp');
    expect(posts(apiRequests)).toEqual([
        [
            'POST',
            'api/v1/tenants/2/roles/3',
            {
                version: 1,
                name: 'Gerätewart',
                description: 'Wie Admin, ohne Benutzer und Einstellungen',
                areas: { dashboard: 'read', search: 'read', devices: 'write' },
                actions: ['book.move', 'device.edit'],
                locations: null,
                device_types: [20],
            },
        ],
    ]);
});

test.describe('Versionskonflikt', () => {
    test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });

    test('der neue Stand wird angezeigt', async ({ page }) => {
        await page.goto('/firefighting/service/staedte/2/rollen/3');
        await page.getByLabel('Name der Rolle').fill('Wart');
        roles.bump(3, { name: 'Gerätewart neu' });
        await page.getByRole('button', { name: 'Speichern' }).click();

        await expect(page.getByRole('alert')).toContainText('Die Rolle wurde inzwischen geändert.');
        await expect(page.getByLabel('Name der Rolle')).toHaveValue('Gerätewart neu');
        await expect(page).toHaveURL(/rollen\/3$/);
    });
});

test('kopieren, Kopie löschen und Rolle in der Liste löschen', async ({ page, apiRequests }) => {
    await page.goto('/firefighting/service/staedte/2?ansicht=rollen');
    await page.getByRole('button', { name: 'Kopieren: Gerätewart' }).click();
    await expect(page).toHaveURL(/staedte\/2\/rollen\/100$/);
    await expect(page.getByTestId('success-notice')).toContainText(
        'Kopie „Gerätewart (Kopie)“ angelegt.',
    );
    await expect(page.getByLabel('Name der Rolle')).toHaveValue('Gerätewart (Kopie)');

    await page.getByRole('button', { name: 'Rolle löschen …' }).click();
    const confirm = page.getByTestId('confirm-dialog');
    await expect(confirm).toContainText('Rolle „Gerätewart (Kopie)“ löschen?');
    await confirm.getByRole('button', { name: 'Löschen' }).click();
    await expect(page).toHaveURL(/staedte\/2\?ansicht=rollen$/);
    await expect(page.getByTestId('roles-notice')).toHaveText(
        'Rolle „Gerätewart (Kopie)“ gelöscht.',
    );
    await expect(page.getByTestId('role-100')).toHaveCount(0);

    await page.getByRole('button', { name: 'Live-Test leer löschen …' }).click();
    await confirm.getByRole('button', { name: 'Löschen' }).click();
    await expect(page.getByTestId('roles-notice')).toHaveText('Rolle „Live-Test leer“ gelöscht.');
    await expect(page.getByTestId('role-4')).toHaveCount(0);
    expect(posts(apiRequests)).toEqual([
        ['POST', 'api/v1/tenants/2/roles', { copy_of: 3 }],
        ['DELETE', 'api/v1/tenants/2/roles/100', {}],
        ['DELETE', 'api/v1/tenants/2/roles/4', {}],
    ]);
});

test('Rolle aus anderer Stadt übernehmen: nur die Rechte', async ({ page, apiRequests }) => {
    await page.goto('/firefighting/service/staedte/2?ansicht=rollen');
    await page.getByRole('button', { name: 'Rolle aus anderer Stadt übernehmen …' }).click();
    const dialog = page.getByTestId('form-dialog');
    await expect(dialog.getByRole('button', { name: 'Übernehmen' })).toBeDisabled();
    await dialog.getByRole('combobox', { name: 'Stadt' }).click();
    await expect(page.getByRole('option', { name: 'Test-Feuerwehr' })).toHaveCount(0);
    await page.getByRole('option', { name: 'Feuerwehr Immendingen', exact: true }).click();
    await dialog.getByRole('combobox', { name: 'Rolle' }).click();
    await page.getByRole('option', { name: 'Geräteträger', exact: true }).click();
    await dialog.getByRole('button', { name: 'Übernehmen' }).click();

    await expect(page).toHaveURL(/staedte\/2\/rollen\/100$/);
    await expect(page.getByTestId('success-notice')).toContainText(
        'Rolle „Geräteträger“ aus „Feuerwehr Immendingen“ übernommen',
    );
    await expect(page.getByTestId('filter-locations').getByLabel('Alle Standorte')).toBeChecked();
    await expect(areaButton(page, 'search', 'Ansehen')).toHaveAttribute('aria-pressed', 'true');
    expect(posts(apiRequests)).toEqual([
        ['POST', 'api/v1/tenants/2/roles/import', { source_role_id: 50 }],
    ]);
});

test('Admin nur ansehen', async ({ page }) => {
    await page.goto('/firefighting/service/staedte/2/rollen/1');
    await expect(page.getByRole('heading', { level: 1, name: 'Rolle ansehen' })).toBeVisible();
    await expect(page.getByTestId('read-only')).toContainText('lässt sich nicht ändern');
    await expect(page.getByRole('button', { name: 'Speichern' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Rolle löschen …' })).toHaveCount(0);
    await expect(areaButton(page, 'users', 'Ändern')).toHaveAttribute('aria-pressed', 'true');
    await expect(areaButton(page, 'users', 'Ändern')).toBeDisabled();
    await page.getByRole('button', { name: 'Zurück' }).click();
    await expect(page).toHaveURL(/staedte\/2\?ansicht=rollen$/);
});

test('PC: die ganze Zeile öffnet die Rolle, bei Admin „Ansehen“; Knöpfe nur ihr Eigenes', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/firefighting/service/staedte/2?ansicht=rollen');
    const admin = page.getByTestId('role-1');
    await expect(admin).toHaveCSS('cursor', 'pointer');
    await admin.locator('.role-name').click();
    await expect(page).toHaveURL(/staedte\/2\/rollen\/1$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Rolle ansehen' })).toBeVisible();
    await page.getByRole('button', { name: 'Zurück' }).click();
    await expect(page).toHaveURL(/staedte\/2\?ansicht=rollen$/);

    // „Löschen …“ fragt nur nach, die Zeile öffnet sich nicht.
    await page.getByRole('button', { name: 'Live-Test leer löschen …' }).click();
    const confirm = page.getByTestId('confirm-dialog');
    await confirm.getByRole('button', { name: 'Abbrechen' }).click();
    await expect(confirm).toBeHidden();
    await expect(page).toHaveURL(/staedte\/2\?ansicht=rollen$/);

    await page.getByTestId('role-4').locator('td.col-count').click();
    await expect(page).toHaveURL(/staedte\/2\/rollen\/4$/);
});

test('Handy: die ganze Karte öffnet die Rolle', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/firefighting/service/staedte/2?ansicht=rollen');
    await page.getByTestId('role-3').locator('.role-name').click();
    await expect(page).toHaveURL(/staedte\/2\/rollen\/3$/);
});

test('Handy: Liste und Editor ohne waagerechten Überstand', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/firefighting/service/staedte/2?ansicht=rollen');
    await expect(page.getByTestId('role-3')).toBeVisible();
    expect(await horizontalOverflow(page)).toBe(0);
    await page.goto('/firefighting/service/staedte/2/rollen/3');
    await expect(page.getByTestId('role-preview')).toBeVisible();
    expect(await horizontalOverflow(page)).toBe(0);
});
