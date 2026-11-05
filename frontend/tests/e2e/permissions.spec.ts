import type { Locator, Page } from '@playwright/test';
import { expect, meBody, permissionsJson, test } from './fixtures';
import { assignableRolesJson, summaryJson, userJson } from './support/admin';
import {
    deviceDetail,
    deviceJson,
    devicesEndpoint,
    eventsEndpoint,
    LOCATIONS,
    locationJson,
} from './support/devices';

/**
 * App nach den Rechten der Rolle (Plan Phase 1, Schritt 1.7b): Seiten, Menü, Handy-Leiste und Knöpfe
 * richten sich nach `permissions` aus auth/me. Jede Abfrage, die die Rolle nicht stellen darf, ist
 * hier nicht simuliert und ließe den Test scheitern (nicht simulierte API-Anfrage).
 */

function me(areas: Readonly<Record<string, 'read' | 'write'>>, actions: readonly string[] = []) {
    return { status: 200, body: meBody('user', 'Gerd', permissionsJson(areas, actions)) } as const;
}

function menu(page: Page): Locator {
    return page.getByRole('navigation', { name: 'Hauptmenü' });
}

async function menuLabels(page: Page): Promise<string[]> {
    const texts = await menu(page).getByRole('link').allInnerTexts();
    return texts.map((text) => text.replace(/\s+/g, ' ').trim());
}

const DEVICE = deviceJson(1, { identifier: 'M1' });

test.describe('Gerätewart: alles zu Geräten, keine Benutzer, kein Import', () => {
    const KEEPER = me(
        {
            dashboard: 'read',
            search: 'read',
            devices: 'write',
            locations: 'write',
            device_types: 'write',
            due: 'write',
            log: 'write',
        },
        [
            'book.move',
            'book.status',
            'device.create',
            'device.edit',
            'device.retire',
            'device.code',
            'devices.bulk',
            'csv.export',
            'mail.log',
        ],
    );

    test.use({
        api: {
            'GET api/v1/auth/me': KEEPER,
            // Ohne `users` fragt das Dashboard keine Freischaltungen (GET users fehlt absichtlich).
            'GET api/v1/device-summary': { status: 200, body: summaryJson() },
            'GET api/v1/device-events': {
                status: 200,
                body: { events: [], total: 0, limit: 8, offset: 0 },
            },
            'GET api/v1/devices': devicesEndpoint([DEVICE]),
            'GET api/v1/device-types': { status: 200, body: { device_types: [] } },
            ...LOCATIONS,
        },
    });

    test('Menü ohne „Benutzer“, Geräteliste ohne Import', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/test/dashboard');
        await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible();
        expect(await menuLabels(page)).toEqual([
            'Dashboard',
            'Suchen',
            'Geräte',
            'Standorte',
            'Gerätetypen',
            'Protokoll',
            'Einstellungen',
        ]);

        await menu(page).getByRole('link', { name: 'Geräte', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Geräte', level: 1 })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Export (CSV)' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Neues Gerät' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Import (CSV)' })).toHaveCount(0);
        await expect(page.getByRole('checkbox', { name: 'M1 auswählen' })).toBeVisible();

        // Benutzerverwaltung per Adresse: still zur Startseite der Rolle.
        await page.goto('/firefighting/test/benutzer');
        await expect(page).toHaveURL(/\/firefighting\/test\/dashboard$/);
    });
});

test.describe('Nur ansehen: Geräteliste und Gerätedetail ohne Aktionen', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': me({ devices: 'read' }),
            'GET api/v1/devices': devicesEndpoint([DEVICE]),
            'GET api/v1/device-types': { status: 200, body: { device_types: [] } },
            ...LOCATIONS,
            ...deviceDetail(DEVICE),
            'GET api/v1/devices/1/events': eventsEndpoint([]),
        },
    });

    test('Startseite ist die Geräteliste; keine Knöpfe, kein Kästchen, kein Aussondern', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/test/');
        await expect(page).toHaveURL(/\/firefighting\/test\/geraete$/);
        expect(await menuLabels(page)).toEqual(['Geräte', 'Einstellungen']);
        await expect(page.getByRole('row', { name: /M1/ })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Export (CSV)' })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Neues Gerät' })).toHaveCount(0);
        await expect(page.getByRole('checkbox')).toHaveCount(1);
        await expect(page.getByRole('checkbox', { name: 'Ausgesonderte anzeigen' })).toBeVisible();

        await page.getByRole('link', { name: 'M1' }).click();
        await expect(page.getByRole('heading', { name: 'M1', level: 1 })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Stammdaten' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Umbuchen / Status ändern' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Bearbeiten' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Prüfdatum ändern' })).toHaveCount(0);
        await expect(page.getByTestId('danger-zone')).toHaveCount(0);
    });
});

test.describe('Benutzer verwalten nur ansehen', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': me({ users: 'read' }),
            'GET api/v1/users': { status: 200, body: { users: [userJson(21)] } },
            'GET api/v1/roles': { status: 200, body: assignableRolesJson() },
            'GET api/v1/invitation-codes': { status: 200, body: { invitation_codes: [] } },
        },
    });

    test('Startseite Benutzer, ohne Freischalten, Bearbeiten und neue Codes', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/test/dashboard');
        await expect(page).toHaveURL(/\/firefighting\/test\/benutzer$/);
        // Die wartende Registrierung steht da, aber ohne Freischalten und Ablehnen.
        await expect(page.getByText('person21@example.org')).toBeVisible();
        await expect(page.getByRole('button', { name: /freischalten/ })).toHaveCount(0);
        await expect(page.getByRole('button', { name: /ablehnen/ })).toHaveCount(0);
        await expect(page.getByRole('button', { name: /bearbeiten/ })).toHaveCount(0);
        await page.getByRole('tab', { name: 'Einladungscodes' }).click();
        await expect(page.getByText('Mit einem Einladungscode registrieren')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Neuer Einladungscode' })).toHaveCount(0);
    });
});

test.describe('Handy: nur umbuchen, kein Status', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': me({ search: 'read' }, ['book.move']),
            'GET api/v1/devices': devicesEndpoint([DEVICE]),
            ...deviceDetail(DEVICE),
            'GET api/v1/locations': {
                status: 200,
                body: {
                    // Datenfilter: vom HLF 20 nur das Fach G1 sichtbar, das Fahrzeug selbst kein Ziel.
                    locations: [
                        {
                            ...locationJson(10, 'HLF 20', [{ id: 11, name: 'G1', count: 1 }]),
                            selectable: false,
                            device_count: null,
                        },
                        locationJson(30, 'Lager', [], 'room', 5),
                    ],
                },
            },
        },
    });

    test('Leiste ohne Prüffristen und Dashboard; Umbuchen ohne Status', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/firefighting/test/');
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        const bar = page.getByRole('navigation', { name: 'Hauptmenü' });
        await expect(bar.getByRole('link')).toHaveText(['Suchen', 'Profil']);

        await page.goto('/firefighting/test/geraete/1');
        await expect(page.getByRole('heading', { name: 'Standort' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Status' })).toHaveCount(0);

        // Die Gruppe des aktuellen Standorts (HLF 20 / G1) ist aufgeklappt: nur das Fach, kein
        // „Ganzes Fahrzeug“, da die Rolle das Fahrzeug selbst nicht sieht (selectable false).
        await page.getByTestId('other-location').click();
        await expect(page.getByRole('button', { name: /^HLF 20/ })).toHaveAttribute(
            'aria-expanded',
            'true',
        );
        await expect(page.getByRole('button', { name: /G1/ })).toBeVisible();
        await expect(page.getByText('Ganzes Fahrzeug, ohne Fach')).toHaveCount(0);
    });
});
