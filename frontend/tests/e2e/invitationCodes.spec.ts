import type { Page } from '@playwright/test';
import { expect, meBody, test, type MockRequest, type MockResponse } from './fixtures';
import { adminOverview } from './support/admin';
import { horizontalOverflow } from './support/layout';
import { delegate } from './support/masterData';

/**
 * Einladungscodes der Benutzerverwaltung (`benutzer?ansicht=einladungscodes`, KONZEPT.md
 * "Admin-Konsole – Entscheidungen", 30): Liste, neuer Code (einmal sichtbar, QR-Code, Kopieren,
 * „Link teilen“, „Aushang drucken“), Widerrufen. Drucken, Teilen und die Zwischenablage ersetzt ein
 * Init-Skript in allen Browsern gleich (window.print, navigator.share, navigator.clipboard).
 */

const NEW_CODE = 'ABCD-EFGH-JKMN';

/** Wie InvitationJson::code. */
function codeJson(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id,
        label: `Code ${String(id)}`,
        status: 'active',
        status_label: 'aktiv',
        use_count: 0,
        max_uses: 50,
        expires_at: '2026-11-30T13:05:00Z',
        created_at: '2026-10-31T13:05:00Z',
        revoked_at: null,
        created_by: { id: 7, label: 'Thomas Keller' },
        ...overrides,
    };
}

function codeServer() {
    const codes = [
        codeJson(2, { label: 'Dienstabend Oktober', use_count: 12 }),
        codeJson(1, {
            label: 'Sommerfest',
            status: 'expired',
            status_label: 'abgelaufen',
            expires_at: '2026-08-01T10:00:00Z',
        }),
    ];
    let nextId = 3;
    const api: Record<string, (request: MockRequest) => MockResponse> = {
        'GET api/v1/users': () => ({ status: 200, body: { users: [] } }),
        'GET api/v1/invitation-codes': () => ({ status: 200, body: { invitation_codes: codes } }),
        'POST api/v1/invitation-codes': (request) => {
            const body = request.body as Record<string, unknown>;
            const code = codeJson(nextId, {
                label: body['label'] ?? null,
                max_uses: body['max_uses'],
            });
            nextId += 1;
            codes.unshift(code);
            return { status: 201, body: { invitation_code: code, code: NEW_CODE } };
        },
    };
    for (const id of [1, 2, 3]) {
        api[`POST api/v1/invitation-codes/${String(id)}/revoke`] = () => {
            const code = codes.find((entry) => entry['id'] === id);
            if (code === undefined) {
                return { status: 404, body: { error: 'not_found', message: 'Nicht gefunden.' } };
            }
            Object.assign(code, {
                status: 'revoked',
                status_label: 'widerrufen',
                revoked_at: '2026-10-31T14:00:00Z',
            });
            return { status: 200, body: { invitation_code: code } };
        };
    }
    return { api, codes };
}

let server = codeServer();

test.beforeEach(() => {
    server = codeServer();
});

test.use({
    timezoneId: 'Europe/Berlin',
    api: {
        'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
        ...adminOverview(),
        ...delegate(() => server.api),
    },
});

interface Recorded {
    readonly printed: number;
    readonly shared: readonly ShareData[];
    readonly copied: readonly string[];
}

/** Ersetzt Drucken, Teilen und die Zwischenablage; `share: false` = Browser ohne Web Share API. */
async function fakeShare(page: Page, share: boolean): Promise<void> {
    await page.addInitScript((withShare: boolean) => {
        const recorded = { printed: 0, shared: [] as ShareData[], copied: [] as string[] };
        Object.assign(window, { __ffRecorded: recorded });
        window.print = () => {
            recorded.printed += 1;
        };
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: (text: string) => {
                    recorded.copied.push(text);
                    return Promise.resolve();
                },
            },
        });
        Object.defineProperty(navigator, 'canShare', { configurable: true, value: undefined });
        Object.defineProperty(navigator, 'share', {
            configurable: true,
            value: withShare
                ? (data: ShareData) => {
                      recorded.shared.push({ ...data });
                      return Promise.resolve();
                  }
                : undefined,
        });
    }, share);
}

function recorded(page: Page): Promise<Recorded> {
    return page.evaluate(() => (window as unknown as { __ffRecorded: Recorded }).__ffRecorded);
}

async function openCodes(page: Page): Promise<void> {
    await page.goto('/firefighting/test/benutzer?ansicht=einladungscodes');
    await expect(page.getByRole('tab', { name: 'Einladungscodes' })).toHaveAttribute(
        'aria-selected',
        'true',
    );
}

async function createCode(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Neuer Einladungscode' }).click();
    const dialog = page.getByRole('dialog', { name: 'Neuer Einladungscode' });
    await dialog.getByLabel('Bezeichnung (freiwillig)').fill('Dienstabend November');
    await dialog.getByRole('button', { name: 'Code erzeugen' }).click();
    await expect(page.getByTestId('fresh-code-value')).toHaveText(NEW_CODE);
}

test.describe('Einladungscodes', () => {
    test('Reiter in der Adresse, aktive Codes, inaktive auf Wunsch', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/firefighting/test/benutzer');
        await expect(page.getByRole('tab', { name: 'Konten' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        await page.getByRole('tab', { name: 'Einladungscodes' }).click();
        await expect(page).toHaveURL(/\/benutzer\?ansicht=einladungscodes$/);
        const table = page.getByTestId('code-table');
        await expect(table.locator('tbody tr')).toHaveCount(1);
        await expect(page.getByTestId('code-2')).toContainText('Dienstabend Oktober');
        await expect(page.getByTestId('code-2')).toContainText('12 von 50 Registrierungen');
        await expect(page.getByTestId('code-2')).toContainText('30.11.2026, 14:05');
        await expect(page.getByTestId('code-2')).toContainText('31.10.2026 · Thomas Keller');

        await page.getByLabel('Abgelaufene, aufgebrauchte und widerrufene anzeigen (1)').check();
        await expect(page.getByTestId('code-1')).toContainText('abgelaufen');
        await expect(page.getByTestId('code-1').getByRole('button')).toHaveCount(0);

        await page.getByRole('tab', { name: 'Konten' }).click();
        await expect(page).toHaveURL(/\/benutzer$/);
    });

    test('neuer Code mit Prüfung, QR-Code, Kopieren, Teilen und Aushang', async ({
        page,
        apiRequests,
        baseURL,
    }) => {
        await fakeShare(page, true);
        await page.setViewportSize({ width: 1280, height: 900 });
        await openCodes(page);
        await page.getByRole('button', { name: 'Neuer Einladungscode' }).click();
        const dialog = page.getByRole('dialog', { name: 'Neuer Einladungscode' });
        await expect(dialog.getByLabel('Gültig (Tage)')).toHaveValue('30');
        await expect(dialog.getByLabel('Registrierungen höchstens')).toHaveValue('50');
        await dialog.getByLabel('Gültig (Tage)').fill('91');
        await expect(dialog.getByText('Bitte 1 bis 90 Tage angeben.')).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Code erzeugen' })).toBeDisabled();
        await dialog.getByLabel('Gültig (Tage)').fill('14');
        await dialog.getByLabel('Registrierungen höchstens').fill('20');
        await dialog.getByLabel('Bezeichnung (freiwillig)').fill('  Dienstabend November ');
        await dialog.getByRole('button', { name: 'Code erzeugen' }).click();

        const fresh = page.getByTestId('fresh-code');
        await expect(fresh.getByRole('heading')).toHaveText(
            'Neuer Einladungscode „Dienstabend November“',
        );
        await expect(page.getByTestId('fresh-code-value')).toHaveText(NEW_CODE);
        await expect(fresh).toContainText('Gültig bis 30.11.2026, 14:05 Uhr');
        const link = `${String(baseURL)}/firefighting/test/registrieren#code=${NEW_CODE}`;
        await expect(page.getByTestId('fresh-link')).toHaveText(link);
        await expect(
            fresh.getByRole('img', { name: 'QR-Code mit dem Link zur Registrierung' }),
        ).toBeVisible();
        await expect(fresh.locator('path.qr-dark')).toHaveAttribute('d', /^M\d/);
        // Neu in der Liste.
        await expect(page.getByTestId('code-3')).toContainText('Dienstabend November');
        expect(apiRequests.filter((r) => r.method === 'POST').map((r) => [r.path, r.body])).toEqual(
            [
                [
                    'api/v1/invitation-codes',
                    { label: 'Dienstabend November', valid_days: 14, max_uses: 20 },
                ],
            ],
        );

        await fresh.getByRole('button', { name: 'Code kopieren' }).click();
        await expect(page.getByTestId('share-feedback')).toHaveText('Code kopiert.');
        await fresh.getByRole('button', { name: 'Link teilen' }).click();
        await expect(page.getByTestId('share-feedback')).toHaveText('Link geteilt.');
        await fresh.getByRole('button', { name: 'Aushang drucken' }).click();
        const calls = await recorded(page);
        expect(calls.copied).toEqual([NEW_CODE]);
        expect(calls.printed).toBe(1);
        expect(calls.shared).toHaveLength(1);
        expect(calls.shared[0]?.url).toBe(link);
        expect(calls.shared[0]?.title).toBe('Registrierung Feuerwehr Test');
        expect(calls.shared[0]?.text).toContain(`Einladungscode ${NEW_CODE}`);

        // Der Aushang erscheint nur beim Drucken, dann ohne die App.
        const poster = page.getByTestId('invitation-poster');
        await expect(poster).toBeHidden();
        await page.emulateMedia({ media: 'print' });
        await expect(poster).toBeVisible();
        await expect(page.locator('#app')).toBeHidden();
        await expect(poster.getByTestId('poster-name')).toHaveText('Feuerwehr Test');
        await expect(poster.getByTestId('poster-code')).toHaveText(NEW_CODE);
        await expect(poster.getByTestId('poster-address')).toHaveText(
            `${new URL(String(baseURL)).host}/firefighting/test/registrieren`,
        );
        await expect(poster.getByTestId('poster-valid')).toContainText(
            'Gültig bis 30.11.2026, 14:05 Uhr',
        );
        await expect(poster).toContainText('QR-Code mit der Handy-Kamera scannen');
        await expect(poster).toContainText('feuerwehr-app@prodad.de');
        await expect(
            poster.getByRole('img', { name: 'QR-Code mit dem Link zur Registrierung' }),
        ).toBeVisible();
        // Passt auf eine A4-Seite (Höhe 297 mm minus 2 × 12 mm Rand, 96 px je Zoll).
        const height = await poster.evaluate((element) => element.getBoundingClientRect().height);
        expect(height).toBeLessThanOrEqual(((297 - 24) / 25.4) * 96);
        await page.emulateMedia({ media: 'screen' });
        await expect(page.locator('#app')).toBeVisible();

        // Schließen: der Code ist weg, die Seite druckt wieder normal.
        await fresh.getByRole('button', { name: 'Schließen' }).click();
        await expect(fresh).toHaveCount(0);
        await expect(poster).toHaveCount(0);
        await page.emulateMedia({ media: 'print' });
        await expect(page.locator('#app')).toBeVisible();
        // Der Code steht nie in einer angefragten Adresse.
        expect(apiRequests.filter((r) => r.url.includes(NEW_CODE))).toEqual([]);
    });

    test('ohne Web Share API wird der Link kopiert', async ({ page, baseURL }) => {
        await fakeShare(page, false);
        await openCodes(page);
        await createCode(page);
        await page.getByRole('button', { name: 'Link teilen' }).click();
        await expect(page.getByTestId('share-feedback')).toHaveText(
            'Link kopiert. Sie können ihn jetzt z. B. in eine Nachricht einfügen.',
        );
        expect((await recorded(page)).copied).toEqual([
            `${String(baseURL)}/firefighting/test/registrieren#code=${NEW_CODE}`,
        ]);
    });

    test('Widerrufen nach Rückfrage, auch den gerade erzeugten Code', async ({
        page,
        apiRequests,
    }) => {
        await openCodes(page);
        await page.getByRole('button', { name: 'Dienstabend Oktober widerrufen' }).click();
        const confirm = page.getByRole('alertdialog', { name: 'Dienstabend Oktober widerrufen?' });
        await expect(confirm.getByRole('button', { name: 'Abbrechen' })).toBeFocused();
        await confirm.getByRole('button', { name: 'Widerrufen' }).click();
        await expect(page.getByTestId('code-success')).toHaveText(
            'Dienstabend Oktober ist widerrufen. Bereits registrierte Konten bleiben.',
        );
        await expect(page.getByTestId('code-2')).toHaveCount(0);
        await expect(page.getByText('Kein Code ist mehr aktiv.')).toBeVisible();

        await createCode(page);
        await page.getByRole('button', { name: 'Dienstabend November widerrufen' }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Widerrufen' }).click();
        await expect(page.getByTestId('fresh-code')).toHaveCount(0);
        expect(
            apiRequests.filter((r) => r.path.endsWith('/revoke')).map((r) => [r.path, r.body]),
        ).toEqual([
            ['api/v1/invitation-codes/2/revoke', {}],
            ['api/v1/invitation-codes/3/revoke', {}],
        ]);
    });

    test('Aushang als PDF genau eine A4-Seite', async ({ page, browserName }) => {
        test.skip(browserName !== 'chromium', 'page.pdf gibt es nur in Chromium');
        await openCodes(page);
        await createCode(page);
        const pdf = (await page.pdf({ format: 'A4' })).toString('latin1');
        expect(pdf.match(/\/Type\s*\/Page[^s]/g)).toHaveLength(1);
    });

    test('am Handy ohne waagerechten Überstand', async ({ page }) => {
        await fakeShare(page, true);
        await page.setViewportSize({ width: 375, height: 800 });
        await openCodes(page);
        await createCode(page);
        await expect(page.getByTestId('code-3')).toBeVisible();
        expect(await horizontalOverflow(page)).toBe(0);
    });
});
