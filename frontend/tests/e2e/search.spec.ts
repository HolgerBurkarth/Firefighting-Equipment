import {
    DATABASE_UNAVAILABLE,
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test,
    type MockResponse,
} from './fixtures';
import {
    deviceDetail,
    deviceJson,
    devicesEndpoint,
    LOCATIONS,
    manyDevices,
    NO_DEVICES,
} from './support/devices';

const ME = { status: 200, body: meBody('user') } as const;
const RECENT_KEY = 'ff-recent-devices:test:7';

const DEVICES: Record<string, unknown>[] = [
    deviceJson(1, { identifier: 'M1' }),
    deviceJson(2, {
        identifier: 'M2',
        location: { id: 12, name: 'G2', path: 'HLF 20 / G2' },
        status: 'defective',
        status_label: 'defekt',
    }),
    deviceJson(3, { identifier: 'M10' }),
    deviceJson(4, {
        identifier: 'PA 3',
        device_type: { id: 2, name: 'Atemschutzgerät' },
        location: { id: 20, name: 'LF 10', path: 'LF 10' },
        manufacturer: 'Dräger',
        status: 'maintenance',
        status_label: 'in Wartung',
    }),
    deviceJson(5, { identifier: 'HRT 5', scan_code: '04006381333931' }),
];

/** Die Umbuchen-Ansicht lädt das Gerät und die Standorte. */
const DEVICE_PAGES = {
    ...Object.fromEntries(DEVICES.flatMap((device) => Object.entries(deviceDetail(device)))),
    ...LOCATIONS,
};

test.describe('Suchen (lokal)', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            'GET api/v1/devices': devicesEndpoint(DEVICES),
            ...DEVICE_PAGES,
        },
    });

    test('sucht beim Tippen, mehrere Wörter mit UND', async ({ page }) => {
        await page.goto('/firefighting/test/suchen');
        const search = page.getByRole('searchbox', { name: 'Gerät suchen' });
        await expect(page.getByText('Hier erscheinen die Geräte')).toBeVisible();
        expect(
            await search.boundingBox().then((box) => Math.round(box?.height ?? 0)),
        ).toBeGreaterThanOrEqual(44);

        await search.fill('m1');
        const cards = page.getByTestId('device-card');
        // Kennung genau zuerst, dann Kennung beginnt damit.
        await expect(cards.locator('.device-identifier')).toHaveText(['M1', 'M10']);
        await expect(page.getByTestId('search-count')).toHaveText('2 Treffer');

        await search.fill('hlf g2');
        await expect(cards).toHaveCount(1);
        await expect(cards.first()).toContainText('M2');
        await expect(cards.first()).toContainText('HLF 20 / G2');
        await expect(cards.first()).toContainText('✕ defekt');

        await search.fill('drager');
        await expect(cards.locator('.device-identifier')).toHaveText(['PA 3']);
        await expect(cards.first()).toContainText('◐ in Wartung');

        await search.fill('gibtsnicht');
        await expect(page.getByTestId('search-count')).toHaveText(
            'Kein Gerät passt zu „gibtsnicht“.',
        );
        await search.fill('');
        await expect(page.getByRole('heading', { name: 'Zuletzt benutzt' })).toBeVisible();
    });

    test('Antippen öffnet das Gerät; danach unter „Zuletzt benutzt“', async ({ page }) => {
        await page.goto('/firefighting/test/suchen');
        await page.getByRole('searchbox', { name: 'Gerät suchen' }).fill('pa 3');
        await page.getByTestId('device-card').first().click();
        await expect(page).toHaveURL(/\/firefighting\/test\/geraete\/4$/);
        await page.getByRole('button', { name: 'Zurück' }).click();
        await expect(page).toHaveURL(/\/suchen$/);
        await expect(page.getByTestId('recent-list').getByTestId('device-card')).toHaveText([
            /PA 3/,
        ]);
    });

    test('Enter: Scan-Code genau öffnet das Gerät', async ({ page }) => {
        await page.goto('/firefighting/test/suchen');
        const search = page.getByRole('searchbox', { name: 'Gerät suchen' });
        await expect(page.getByText('Hier erscheinen die Geräte')).toBeVisible();
        await search.fill('04006381333931');
        await search.press('Enter');
        await expect(page).toHaveURL(/\/geraete\/5$/);
    });

    test('Enter: Kennung ohne Groß-/Kleinschreibung, auch wenn mehrere passen', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/suchen');
        const search = page.getByRole('searchbox', { name: 'Gerät suchen' });
        await expect(page.getByText('Hier erscheinen die Geräte')).toBeVisible();
        await search.fill('m1');
        await search.press('Enter');
        await expect(page).toHaveURL(/\/geraete\/1$/);
    });

    test('Enter: ein einziger Treffer öffnet das Gerät', async ({ page }) => {
        await page.goto('/firefighting/test/suchen');
        const search = page.getByRole('searchbox', { name: 'Gerät suchen' });
        await search.fill('dräger');
        await expect(page.getByTestId('search-count')).toHaveText('1 Treffer');
        await search.press('Enter');
        await expect(page).toHaveURL(/\/geraete\/4$/);
    });

    test('Enter ohne Treffer: Hinweis, Text bleibt im Suchfeld', async ({ page }) => {
        await page.goto('/firefighting/test/suchen');
        const search = page.getByRole('searchbox', { name: 'Gerät suchen' });
        await search.fill('XYZ-999');
        await expect(page.getByTestId('search-count')).toBeVisible();
        await search.press('Enter');
        await expect(page.getByTestId('search-not-found')).toHaveText(
            'Kein Gerät mit diesem Code gefunden.',
        );
        await expect(search).toHaveValue('XYZ-999');
        await search.press('Backspace');
        await expect(page.getByTestId('search-not-found')).toBeHidden();
        await expect(page).toHaveURL(/\/suchen$/);
    });

    test('„Zuletzt benutzt“: Reihenfolge, nur vorhandene Geräte', async ({ page }) => {
        // Vor dem Laden gesetzt: Ein reload() mitten in GET devices meldet WebKit als Skriptfehler.
        await page.addInitScript((key) => {
            window.localStorage.setItem(key, JSON.stringify([4, 99, 2, 1]));
        }, RECENT_KEY);
        await page.goto('/firefighting/test/suchen');
        await expect(
            page
                .getByTestId('recent-list')
                .getByTestId('device-card')
                .locator('.device-identifier'),
        ).toHaveText(['PA 3', 'M2', 'M1']);
    });
});

test.describe('Abmelden löscht „Zuletzt benutzt“', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            'GET api/v1/devices': devicesEndpoint(DEVICES),
            ...DEVICE_PAGES,
            'POST api/v1/auth/logout': { status: 200, body: { logged_out: true } },
        },
    });

    test('Liste ist danach leer', async ({ page }) => {
        await page.goto('/firefighting/test/geraete/2');
        await expect(
            page.getByRole('heading', { level: 1, name: 'M2 · Atemschutzmaske' }),
        ).toBeVisible();
        expect(await page.evaluate((key) => window.localStorage.getItem(key), RECENT_KEY)).toBe(
            '[2]',
        );
        await page.goto('/firefighting/test/profil');
        await page.getByRole('button', { name: 'Abmelden' }).click();
        await expect(page).toHaveURL(/\/anmelden$/);
        expect(
            await page.evaluate((key) => window.localStorage.getItem(key), RECENT_KEY),
        ).toBeNull();
    });
});

test.describe('Viele Geräte', () => {
    test.describe('lokal in Blöcken', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': ME,
                'GET api/v1/devices': devicesEndpoint(manyDevices(250)),
            },
        });

        test('lädt zwei Blöcke, zeigt höchstens 50 Treffer', async ({ page, apiRequests }) => {
            await page.goto('/firefighting/test/suchen');
            const search = page.getByRole('searchbox', { name: 'Gerät suchen' });
            await search.fill('m230');
            await expect(page.getByTestId('device-card')).toHaveCount(1);
            const offsets = apiRequests
                .filter((r) => r.path === 'api/v1/devices')
                .map((r) => new URL(r.url).searchParams.get('offset'));
            expect(offsets).toEqual(['0', '200']);

            await search.fill('m');
            await expect(page.getByTestId('search-count')).toHaveText('250 Treffer');
            await expect(page.getByTestId('search-narrow')).toHaveText(
                'Mehr als 50 Treffer – bitte weiter eingrenzen.',
            );
            await expect(page.getByTestId('device-card')).toHaveCount(50);
        });
    });

    test.describe('auf dem Server (mehr als 2000)', () => {
        const hits = [
            deviceJson(4711, { identifier: 'ABC-1' }),
            deviceJson(4712, { identifier: 'ABC-2' }),
        ];
        test.use({
            api: {
                'GET api/v1/auth/me': ME,
                'GET api/v1/devices': devicesEndpoint(
                    manyDevices(200),
                    (q) =>
                        hits.filter((hit) =>
                            String(hit['identifier']).toLowerCase().includes(q.toLowerCase()),
                        ),
                    2001,
                ),
                'GET api/v1/devices/4711': { status: 200, body: { device: hits[0] } },
                ...LOCATIONS,
            },
        });

        test('sucht per q und zeigt „Zuletzt benutzt“ vom Server', async ({
            page,
            apiRequests,
        }) => {
            await page.addInitScript((key) => {
                window.localStorage.setItem(key, JSON.stringify([4711]));
            }, RECENT_KEY);
            await page.goto('/firefighting/test/suchen');
            await expect(page.getByTestId('recent-list').locator('.device-identifier')).toHaveText([
                'ABC-1',
            ]);

            await page.getByRole('searchbox', { name: 'Gerät suchen' }).fill('abc');
            await expect(page.getByTestId('search-count')).toHaveText('2 Treffer');
            const list = apiRequests.filter((r) => r.path === 'api/v1/devices');
            // Nur der erste Block, dann die Suche.
            expect(list.map((r) => new URL(r.url).searchParams.get('offset'))).toEqual(['0', '0']);
            expect(new URL(list.at(-1)?.url ?? '').searchParams.get('q')).toBe('abc');

            // Enter: Kennung genau (Treffer der Suche auf dem Server) öffnet das Gerät.
            await page.getByRole('searchbox', { name: 'Gerät suchen' }).fill('ABC-1');
            await page.getByRole('searchbox', { name: 'Gerät suchen' }).press('Enter');
            await expect(page).toHaveURL(/\/geraete\/4711$/);
        });
    });
});

test.describe('Liste nicht ladbar', () => {
    let calls = 0;
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            'GET api/v1/devices': (request): MockResponse => {
                calls += 1;
                return calls === 1 ? DATABASE_UNAVAILABLE : devicesEndpoint(DEVICES)(request);
            },
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        calls = 0;
    });

    test('zeigt den Fehler und lädt per Knopf erneut', async ({ page }) => {
        await page.goto('/firefighting/test/suchen');
        await expect(page.getByRole('alert')).toContainText('database_unavailable');
        await page.getByRole('button', { name: 'Erneut versuchen' }).click();
        await expect(page.getByText('Hier erscheinen die Geräte')).toBeVisible();
        await page.getByRole('searchbox', { name: 'Gerät suchen' }).fill('m2');
        await expect(page.getByTestId('device-card')).toHaveCount(1);
    });
});

test.describe('Dashboard des Admins', () => {
    test.use({
        api: { 'GET api/v1/auth/me': { status: 200, body: meBody('admin') }, ...NO_DEVICES },
    });

    test('Handy-Breite: Leiste unten, „Suchen“ erreichbar', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/firefighting/test/dashboard');
        const nav = page.getByRole('navigation', { name: 'Hauptmenü' });
        await expect(nav.getByRole('link')).toHaveText([
            'Dashboard',
            'Suchen',
            'Prüffristen',
            'Profil',
        ]);
        await expect(nav.getByRole('link', { name: 'Dashboard' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        await nav.getByRole('link', { name: 'Suchen' }).click();
        await expect(page).toHaveURL(/\/suchen$/);
    });

    test('PC-Breite: Leiste unten, „Suchen“ erreichbar', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/test/dashboard');
        await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
        const nav = page.getByRole('navigation', { name: 'Hauptmenü' });
        await expect(nav.getByRole('link')).toHaveText([
            'Dashboard',
            'Suchen',
            'Prüffristen',
            'Profil',
        ]);
        await nav.getByRole('link', { name: 'Suchen' }).click();
        await expect(page).toHaveURL(/\/suchen$/);
    });
});

test.describe('Dashboard des Super-Admins', () => {
    test.use({ api: { 'GET api/v1/auth/me': { status: 200, body: meBody('superadmin') } } });

    test('keine Leiste, auch bei Handy-Breite', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/firefighting/service/dashboard');
        await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
        await expect(page.getByRole('navigation', { name: 'Hauptmenü' })).toHaveCount(0);
    });
});

test.describe('Dunkel', () => {
    test.use({
        api: { 'GET api/v1/auth/me': ME, 'GET api/v1/devices': devicesEndpoint(DEVICES) },
        colorScheme: 'dark',
    });

    test('Karten und Suchfeld auf dunkler Fläche', async ({ page }) => {
        await page.goto('/firefighting/test/suchen');
        await page.getByRole('searchbox', { name: 'Gerät suchen' }).fill('m1');
        await expect(page.getByTestId('device-card').first()).toHaveCSS(
            'background-color',
            'rgb(27, 34, 43)',
        );
        await expect(page.locator('.search-field')).toHaveCSS(
            'background-color',
            'rgb(27, 34, 43)',
        );
    });
});
