import type { Page } from '@playwright/test';
import {
    DATABASE_UNAVAILABLE,
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { deviceDetail, deviceJson, devicesEndpoint, LOCATIONS } from './support/devices';

/** M1 an HLF 20 / G1 (11), einsatzbereit. */
const M1 = deviceJson(1);

const PATHS: Record<number, { id: number; name: string; path: string }> = {
    10: { id: 10, name: 'HLF 20', path: 'HLF 20' },
    12: { id: 12, name: 'G2', path: 'HLF 20 / G2' },
    20: { id: 20, name: 'LF 10', path: 'LF 10' },
    30: { id: 30, name: 'Lager', path: 'Lager' },
};

/** POST devices/1/state: übernommen, Standort laut Anfrage. */
function applied(request: MockRequest): MockResponse {
    const body = request.body as Record<string, unknown>;
    const target = typeof body['location_id'] === 'number' ? PATHS[body['location_id']] : undefined;
    return {
        status: 200,
        body: {
            device: deviceJson(1, { version: 2, ...(target && { location: target }) }),
            result: {
                outcome: 'applied',
                outcome_label: 'übernommen',
                reason: null,
                reason_label: null,
                message: 'Übernommen.',
                stale_confirmed: false,
                client_ref: body['client_ref'],
                replayed: false,
                notice_id: null,
            },
        },
    };
}

const COMMON = {
    'GET api/v1/auth/me': { status: 200, body: meBody('user') },
    'GET api/v1/devices': devicesEndpoint([M1]),
    ...deviceDetail(M1),
    'POST api/v1/devices/1/state': applied,
};

function stateBodies(requests: readonly MockRequest[]): Record<string, unknown>[] {
    return requests
        .filter((r) => r.method === 'POST' && r.path === 'api/v1/devices/1/state')
        .map((r) => r.body as Record<string, unknown>);
}

async function openFromSearch(page: Page): Promise<void> {
    await page.goto('/firefighting/test/suchen');
    await page.getByRole('searchbox', { name: 'Gerät suchen' }).fill('M1');
    await page.getByTestId('device-card').first().click();
    await expect(
        page.getByRole('heading', { level: 1, name: 'M1 · Atemschutzmaske' }),
    ).toBeVisible();
}

async function openPicker(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Anderen Standort suchen …' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Standort wählen' })).toBeVisible();
}

const group = (page: Page, name: string) =>
    page.getByTestId('picker-group').filter({ hasText: name });
const entry = (page: Page, name: string) =>
    page.getByTestId('picker-entry').filter({ hasText: name });

test.describe('Standortwahl', () => {
    test.use({ api: { ...COMMON, ...LOCATIONS } });

    test('bildschirmfüllend; Gruppe des aktuellen Standorts aufgeklappt', async ({ page }) => {
        await openFromSearch(page);
        await openPicker(page);
        await expect(page).toHaveURL(/\/geraete\/1\?standortwahl=1$/);
        await expect(
            page.getByRole('heading', { level: 1, name: 'Standort wählen' }),
        ).toBeFocused();
        await expect(page.getByText('für M1 · Atemschutzmaske')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Speichern' })).toHaveCount(0);
        await expect(page.getByTestId('picker-hint')).toHaveText(
            'Fahrzeug antippen klappt die Fächer auf. Fach antippen wählt es.',
        );
        await expect(page.getByTestId('picker-group')).toHaveText([
            /HLF 20\s*9 Geräte/,
            /LF 10\s*2 Geräte/,
            /Lager\s*5 Geräte/,
        ]);
        await expect(group(page, 'HLF 20')).toHaveAttribute('aria-expanded', 'true');
        // Ohne Fächer: kein Aufklappen, Antippen wählt.
        await expect(group(page, 'LF 10')).not.toHaveAttribute('aria-expanded');
        await expect(page.getByTestId('picker-entry')).toHaveText([
            'Ganzes Fahrzeug, ohne Fach',
            /G1\s*aktuell\s*6 Geräte/,
            /G2\s*3 Geräte/,
        ]);
        await expect(entry(page, 'G1').getByRole('img', { name: 'gewählt' })).toBeVisible();

        // Ganze Zeile klappt zu und wieder auf.
        await group(page, 'HLF 20').click();
        await expect(group(page, 'HLF 20')).toHaveAttribute('aria-expanded', 'false');
        await expect(page.getByTestId('picker-entry')).toHaveCount(0);
        await group(page, 'HLF 20').click();
        await expect(page.getByTestId('picker-entry')).toHaveCount(3);
    });

    test('Fach wählen: zurück zum Umbuchen, Eingaben bleiben, Speichern bucht dorthin', async ({
        page,
        apiRequests,
    }) => {
        await openFromSearch(page);
        await page.getByRole('button', { name: 'in Wartung', exact: true }).click();
        await page.getByLabel('Kommentar (optional)').fill('nach Übung');
        await openPicker(page);
        await entry(page, 'G2').click();

        await expect(page).toHaveURL(/\/geraete\/1$/);
        await expect(page.getByRole('button', { name: 'Anderen Standort suchen …' })).toBeFocused();
        const choices = page.getByTestId('location-choice');
        await expect(choices).toHaveText([/HLF 20 \/ G1\s*aktuell/, /HLF 20 \/ G2\s*gewählt/]);
        await expect(choices.filter({ hasText: 'G2' })).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByRole('button', { name: 'in Wartung', exact: true })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expect(page.getByLabel('Kommentar (optional)')).toHaveValue('nach Übung');

        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('booking-notice')).toHaveText(
            '✓ M1 → HLF 20 / G2 · einsatzbereit',
        );
        expect(stateBodies(apiRequests)).toEqual([
            expect.objectContaining({
                version: 1,
                location_id: 12,
                status: 'maintenance',
                comment: 'nach Übung',
            }),
        ]);
        // Die Standortwahl steht nicht zwischen „Suchen“ und dem Gerät in der Historie.
        expect(await page.evaluate(() => window.history.state as unknown)).toMatchObject({
            forward: '/geraete/1',
        });
    });

    test('„Ganzes Fahrzeug, ohne Fach“ bucht auf das Fahrzeug', async ({ page, apiRequests }) => {
        await openFromSearch(page);
        await openPicker(page);
        await entry(page, 'Ganzes Fahrzeug, ohne Fach').click();
        await expect(page.getByTestId('location-choice')).toHaveText([
            /HLF 20 \/ G1\s*aktuell/,
            /HLF 20\s*gewählt/,
        ]);
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('booking-notice')).toHaveText(
            '✓ M1 → HLF 20 · einsatzbereit',
        );
        expect(stateBodies(apiRequests)).toEqual([
            expect.objectContaining({ version: 1, location_id: 10 }),
        ]);
    });

    test('Standort ohne Fächer: Antippen wählt ihn', async ({ page, apiRequests }) => {
        await openFromSearch(page);
        await openPicker(page);
        await group(page, 'LF 10').click();
        await expect(page).toHaveURL(/\/geraete\/1$/);
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/\/suchen$/);
        expect(stateBodies(apiRequests)).toEqual([expect.objectContaining({ location_id: 20 })]);
        // Neues Ziel unter den zuletzt benutzten Standorten.
        expect(
            await page.evaluate(() => window.localStorage.getItem('ff-recent-locations:test:7')),
        ).toBe('[20]');
    });

    test('Suche: flache Trefferliste, Fächer mit ihrem Fahrzeug', async ({ page, apiRequests }) => {
        await openFromSearch(page);
        await openPicker(page);
        const search = page.getByRole('searchbox', { name: 'Standort suchen' });
        await search.fill('hlf g2');
        await expect(page.getByTestId('picker-hint')).toHaveText('1 Treffer');
        await expect(page.getByTestId('picker-entry')).toHaveText([/G2\s*HLF 20\s*3 Geräte/]);
        await expect(page.getByTestId('picker-group')).toHaveCount(0);

        await search.fill('xyz');
        await expect(page.getByText('Kein Standort passt zu „xyz“.')).toBeVisible();

        await search.fill('LAGER');
        await expect(page.getByTestId('picker-entry')).toHaveText([/Lager\s*5 Geräte/]);
        await entry(page, 'Lager').click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('booking-notice')).toHaveText('✓ M1 → Lager · einsatzbereit');
        expect(stateBodies(apiRequests)).toEqual([expect.objectContaining({ location_id: 30 })]);
    });

    test('Abbrechen und Zurück-Knopf schließen nur die Auswahl', async ({ page }) => {
        await openFromSearch(page);
        await page.getByLabel('Kommentar (optional)').fill('bleibt stehen');
        await openPicker(page);
        await page.getByRole('button', { name: 'Abbrechen, zurück zum Umbuchen' }).click();
        await expect(page).toHaveURL(/\/geraete\/1$/);
        await expect(page.getByTestId('location-choice')).toHaveText([/HLF 20 \/ G1\s*aktuell/]);
        await expect(page.getByLabel('Kommentar (optional)')).toHaveValue('bleibt stehen');

        await openPicker(page);
        await page.goBack();
        await expect(page).toHaveURL(/\/geraete\/1$/);
        await expect(page.getByLabel('Kommentar (optional)')).toHaveValue('bleibt stehen');
        // Vorwärts öffnet die Auswahl wieder.
        await page.goForward();
        await expect(
            page.getByRole('heading', { level: 1, name: 'Standort wählen' }),
        ).toBeVisible();
        await page.goBack();
        await page.goBack();
        await expect(page).toHaveURL(/\/suchen$/);
    });

    test('direkt mit ?standortwahl=1 geöffnet: Schließen bleibt beim Gerät', async ({ page }) => {
        await page.goto('/firefighting/test/geraete/1?standortwahl=1');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Standort wählen' }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Abbrechen, zurück zum Umbuchen' }).click();
        await expect(page).toHaveURL(/\/geraete\/1$/);
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeVisible();
    });

    for (const scheme of ['light', 'dark'] as const) {
        test(`„Anderen Standort suchen …“: umrandete Schaltfläche, volle Breite (${scheme})`, async ({
            page,
        }) => {
            await page.emulateMedia({ colorScheme: scheme });
            await page.goto('/firefighting/test/geraete/1');
            const button = page.getByRole('button', { name: 'Anderen Standort suchen …' });
            await expect(button).toBeVisible();
            // Lupe links, Pfeil rechts.
            await expect(button.locator('svg')).toHaveCount(2);

            const box = await button.boundingBox();
            const choice = await page.getByTestId('location-choice').first().boundingBox();
            const status = await page.getByRole('heading', { name: 'Status' }).boundingBox();
            expect(Math.round(box?.height ?? 0)).toBeGreaterThanOrEqual(48);
            expect(Math.abs((box?.width ?? 0) - (choice?.width ?? 0))).toBeLessThan(1);
            // Mehr Abstand nach unten als zwischen den Standorten.
            const gap = (status?.y ?? 0) - ((box?.y ?? 0) + (box?.height ?? 0));
            expect(gap).toBeGreaterThanOrEqual(28);

            // Umrandet in der Farbe der Schrift, die sich vom Hintergrund abhebt.
            const style = await button.evaluate((element) => {
                const css = getComputedStyle(element);
                return {
                    width: css.borderTopWidth,
                    border: css.borderTopColor,
                    color: css.color,
                    background: css.backgroundColor,
                };
            });
            expect(style.width).toBe('2px');
            expect(style.border).toBe(style.color);
            expect(style.border).not.toBe(style.background);
        });
    }

    test('„Anderen Standort suchen …“: Hover am PC hinterlegt kräftig', async ({
        page,
        isMobile,
    }) => {
        test.skip(isMobile, 'Kein Hover am Handy');
        await page.goto('/firefighting/test/geraete/1');
        const button = page.getByRole('button', { name: 'Anderen Standort suchen …' });
        const before = await button.evaluate(
            (element) => getComputedStyle(element).backgroundColor,
        );
        await button.hover();
        await expect(button).not.toHaveCSS('background-color', before);
        await expect(button).not.toHaveCSS('box-shadow', 'none');
    });

    test('Handy-Breite: Einträge mindestens 44 px, kein seitliches Überstehen', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 320, height: 640 });
        await page.goto('/firefighting/test/geraete/1?standortwahl=1');
        await expect(page.getByTestId('picker-entry')).toHaveCount(3);
        for (const element of [
            page.getByRole('button', { name: 'Abbrechen, zurück zum Umbuchen' }),
            page.getByRole('searchbox', { name: 'Standort suchen' }),
            ...(await page.getByTestId('picker-group').all()),
            ...(await page.getByTestId('picker-entry').all()),
        ]) {
            const box = await element.boundingBox();
            expect(Math.round(box?.height ?? 0)).toBeGreaterThanOrEqual(44);
        }
        // Wie in booking.spec.ts: nicht über clientWidth (Scrollleiste des emulierten iPhones).
        const overflow = await page.evaluate(() => {
            const width = document.documentElement.getBoundingClientRect().right;
            return [...document.querySelectorAll('body *')]
                .filter((element) => element.getBoundingClientRect().right > width + 0.5)
                .map((element) => `${element.tagName}.${element.className}`);
        });
        expect(overflow).toEqual([]);
    });
});

test.describe('Standorte nicht ladbar', () => {
    let failing = true;
    test.use({
        api: {
            ...COMMON,
            'GET api/v1/locations': (): MockResponse =>
                failing ? DATABASE_UNAVAILABLE : LOCATIONS['GET api/v1/locations'],
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        failing = true;
    });

    test('Fehler mit erneutem Versuch', async ({ page }) => {
        await page.goto('/firefighting/test/geraete/1?standortwahl=1');
        await expect(page.getByRole('alert')).toContainText('database_unavailable');
        failing = false;
        await page.getByRole('button', { name: 'Erneut versuchen' }).click();
        await expect(group(page, 'HLF 20')).toHaveAttribute('aria-expanded', 'true');
        await expect(page.getByTestId('picker-entry')).toHaveCount(3);
    });
});
