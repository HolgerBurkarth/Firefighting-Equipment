import type { Page } from '@playwright/test';
import {
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { CURRENT_SESSION_ONLY } from './support/profile';

/**
 * Seite „Claude verbinden“ (docs/PLAN-MCP.md, Schritt M4) und eigene Claude-Verbindungen unter
 * „Angemeldete Geräte“ (O5). Die API simuliert page.route(); der Rücksprung zu claude.ai bzw.
 * localhost wird abgefangen (es verlässt keine Anfrage den Testrechner).
 */

const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';
const PARAMS = {
    response_type: 'code',
    client_id: 'client-abc',
    redirect_uri: CALLBACK,
    code_challenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
    code_challenge_method: 'S256',
    state: 'zustand 1&2',
    scope: 'read write',
    resource: 'https://www.prodad.de/firefighting/test/mcp',
};
const PAGE = `/firefighting/test/claude-verbinden?${new URLSearchParams(PARAMS).toString()}`;

const SUMMARY_BODY = {
    tenant: { name: 'Feuerwehr Test' },
    client: { name: 'Claude' },
    target: { host: 'claude.ai', local: false },
    requested_access: 'write',
    requested_access_label: 'Ändern',
};
const SUMMARY: MockResponse = { status: 200, body: SUMMARY_BODY };

const CHECK = 'POST api/v1/oauth/authorize/check';
const AUTHORIZE = 'POST api/v1/oauth/authorize';

function decisionOf(request: MockRequest): unknown {
    return (request.body as { decision?: unknown } | undefined)?.decision;
}

/** authorize: Vorschau mit Zugang Lesen, Verbinden mit Code, Ablehnen mit access_denied. */
function authorizeServer(request: MockRequest): MockResponse {
    switch (decisionOf(request)) {
        case 'preview':
            return {
                status: 200,
                body: { access: 'read', access_label: 'Lesen', user: { display_name: 'Erika' } },
            };
        case 'allow':
            return {
                status: 200,
                body: {
                    access: 'read',
                    access_label: 'Lesen',
                    redirect_url: `${CALLBACK}?code=ffc_abc&state=zustand%201%262&iss=x`,
                },
            };
        default:
            return {
                status: 200,
                body: { redirect_url: `${CALLBACK}?error=access_denied&state=zustand%201%262` },
            };
    }
}

/** Rücksprung zu Claude abfangen; liefert die aufgerufenen Adressen. */
async function catchCallbacks(page: Page): Promise<string[]> {
    const calls: string[] = [];
    await page.route(
        /^(https:\/\/claude\.(ai|com)|http:\/\/(localhost|127\.0\.0\.1):\d+\/callback)/,
        async (route) => {
            calls.push(route.request().url());
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<p>Claude</p>' });
        },
    );
    return calls;
}

async function login(page: Page, password = 'richtig-1234'): Promise<void> {
    await page.getByLabel('E-Mail-Adresse').fill('erika@example.org');
    await page.getByLabel('Passwort', { exact: true }).fill(password);
    await page.getByTestId('claude-login-submit').click();
}

test.describe('Claude verbinden', () => {
    test.describe('gültige Anfrage', () => {
        test.use({ api: { [CHECK]: SUMMARY, [AUTHORIZE]: authorizeServer } });

        test('zeigt Stadt, Ziel und Zugang, verbindet nach der Anmeldung', async ({
            page,
            apiRequests,
        }) => {
            const calls = await catchCallbacks(page);
            await page.goto(PAGE);

            await expect(
                page.getByRole('heading', { level: 1, name: 'Claude verbinden' }),
            ).toBeVisible();
            await expect(page.getByTestId('claude-tenant')).toHaveText('Feuerwehr Test');
            await expect(page.getByTestId('claude-target')).toHaveText('claude.ai');
            await expect(page.getByTestId('claude-access')).toHaveText('Ändern');
            await expect(page.getByLabel('E-Mail-Adresse')).toBeFocused();

            await login(page);
            // Gewährt ist höchstens der Zugang des Kontos.
            await expect(page.getByTestId('claude-granted')).toHaveText('Lesen');
            await expect(page.getByTestId('claude-access')).toHaveText('Lesen');
            await expect(page.getByTestId('claude-consent')).toContainText('Angemeldet als Erika');

            await page.getByTestId('claude-allow').click();
            await expect
                .poll(() => calls)
                .toEqual([`${CALLBACK}?code=ffc_abc&state=zustand%201%262&iss=x`]);

            // Parameter unverändert an den Server, Passwort bei Vorschau und Verbinden.
            expect(apiRequests.map((r) => [r.path, r.body])).toEqual([
                ['api/v1/oauth/authorize/check', { params: PARAMS }],
                [
                    'api/v1/oauth/authorize',
                    {
                        params: PARAMS,
                        decision: 'preview',
                        email: 'erika@example.org',
                        password: 'richtig-1234',
                    },
                ],
                [
                    'api/v1/oauth/authorize',
                    {
                        params: PARAMS,
                        decision: 'allow',
                        email: 'erika@example.org',
                        password: 'richtig-1234',
                    },
                ],
            ]);
        });

        test('Ablehnen führt ohne Anmeldung mit access_denied zurück', async ({
            page,
            apiRequests,
        }) => {
            const calls = await catchCallbacks(page);
            await page.goto(PAGE);
            await expect(page.getByTestId('claude-request')).toBeVisible();

            await page.getByRole('button', { name: 'Ablehnen' }).click();

            await expect
                .poll(() => calls)
                .toEqual([`${CALLBACK}?error=access_denied&state=zustand%201%262`]);
            expect(apiRequests.at(-1)?.body).toEqual({ params: PARAMS, decision: 'deny' });
        });

        test('zeigt Claude Code auf diesem Rechner als Ziel', async ({ page }) => {
            await page.route('**/api/v1/oauth/authorize/check', (route) =>
                route.fulfill({
                    status: 200,
                    json: { ...SUMMARY_BODY, target: { host: 'localhost', local: true } },
                }),
            );
            await page.goto(PAGE);
            await expect(page.getByTestId('claude-target')).toHaveText(
                'Claude Code auf diesem Rechner (localhost)',
            );
        });
    });

    test.describe('falsches Passwort', () => {
        test.use({
            allowedConsoleError: HTTP_ERROR_CONSOLE,
            api: {
                [CHECK]: SUMMARY,
                [AUTHORIZE]: {
                    status: 401,
                    body: {
                        error: 'invalid_credentials',
                        message: 'E-Mail-Adresse oder Passwort ist falsch.',
                    },
                },
            },
        });

        test('leert das Passwort und bleibt auf der Seite', async ({ page }) => {
            await page.goto(PAGE);
            await login(page, 'falsch-falsch');
            await expect(page.getByRole('alert')).toContainText(
                'E-Mail-Adresse oder Passwort ist falsch.',
            );
            await expect(page.getByLabel('Passwort', { exact: true })).toHaveValue('');
            await expect(page.getByLabel('Passwort', { exact: true })).toBeFocused();
        });
    });

    test.describe('ohne Claude-Zugang', () => {
        test.use({
            allowedConsoleError: HTTP_ERROR_CONSOLE,
            api: {
                [CHECK]: SUMMARY,
                [AUTHORIZE]: (request) =>
                    decisionOf(request) === 'deny'
                        ? authorizeServer(request)
                        : {
                              status: 403,
                              body: {
                                  error: 'claude_access_missing',
                                  message:
                                      'Kein Claude-Zugang – bitte wenden Sie sich an Ihren Admin.',
                              },
                          },
            },
        });

        test('zeigt den Hinweis ohne Verbinden', async ({ page }) => {
            const calls = await catchCallbacks(page);
            await page.goto(PAGE);
            await login(page);

            await expect(page.getByTestId('claude-consent')).toContainText(
                'Kein Claude-Zugang – bitte wenden Sie sich an Ihren Admin.',
            );
            await expect(page.getByTestId('claude-allow')).toHaveCount(0);
            await expect(page.getByTestId('claude-login')).toHaveCount(0);
            expect(calls).toEqual([]);
        });
    });

    test.describe('zu viele Versuche', () => {
        test.use({
            allowedConsoleError: HTTP_ERROR_CONSOLE,
            api: {
                [CHECK]: SUMMARY,
                [AUTHORIZE]: {
                    status: 429,
                    headers: { 'Retry-After': '75' },
                    body: { error: 'too_many_attempts', message: 'Zu viele Fehlversuche.' },
                },
            },
        });

        test('sperrt die Anmeldung bis Retry-After', async ({ page }) => {
            await page.clock.install();
            await page.goto(PAGE);
            await login(page);
            await expect(page.getByTestId('claude-login-submit')).toHaveText(
                'Anmelden wieder möglich in 1:15',
            );
            await expect(page.getByTestId('claude-login-submit')).toBeDisabled();
            await page.clock.runFor(76_000);
            await expect(page.getByTestId('claude-login-submit')).toHaveText(
                'Anmelden und fortfahren',
            );
        });
    });

    test.describe('ungültige Anfrage', () => {
        test.use({
            allowedConsoleError: HTTP_ERROR_CONSOLE,
            api: {
                [CHECK]: {
                    status: 400,
                    body: {
                        error: 'invalid_request',
                        message:
                            'Die Rücksprung-Adresse ist für diese Anwendung nicht registriert.',
                    },
                },
            },
        });

        test('ohne gültigen Rücksprung kein Weg zurück und kein Formular', async ({ page }) => {
            await page.goto(PAGE);
            await expect(page.getByTestId('claude-check-error')).toContainText(
                'Die Rücksprung-Adresse ist für diese Anwendung nicht registriert.',
            );
            await expect(page.getByRole('button', { name: 'Zurück zu Claude' })).toHaveCount(0);
            await expect(page.getByLabel('E-Mail-Adresse')).toHaveCount(0);
        });
    });

    test.describe('Fehler mit Rücksprung', () => {
        test.use({
            allowedConsoleError: HTTP_ERROR_CONSOLE,
            api: {
                [CHECK]: {
                    status: 400,
                    body: {
                        error: 'invalid_request',
                        message: 'PKCE mit S256 ist Pflicht.',
                        details: {
                            redirect_url: `${CALLBACK}?error=invalid_request&state=zustand%201%262`,
                        },
                    },
                },
            },
        });

        test('bietet „Zurück zu Claude“ an', async ({ page }) => {
            const calls = await catchCallbacks(page);
            await page.goto(PAGE);
            await expect(page.getByTestId('claude-check-error')).toContainText(
                'PKCE mit S256 ist Pflicht.',
            );
            await page.getByRole('button', { name: 'Zurück zu Claude' }).click();
            await expect
                .poll(() => calls)
                .toEqual([`${CALLBACK}?error=invalid_request&state=zustand%201%262`]);
        });
    });

    test.describe('fremder Rücksprung vom Server', () => {
        test.use({
            allowedConsoleError: HTTP_ERROR_CONSOLE,
            api: {
                [CHECK]: SUMMARY,
                [AUTHORIZE]: {
                    status: 200,
                    body: { redirect_url: 'https://evil.example/?code=x' },
                },
            },
        });

        test('folgt nie einer Adresse, die nicht zur Anfrage passt', async ({ page }) => {
            const calls = await catchCallbacks(page);
            await page.route('https://evil.example/**', (route) => {
                calls.push(route.request().url());
                return route.fulfill({ status: 200, body: 'evil' });
            });
            await page.goto(PAGE);
            await expect(page.getByTestId('claude-request')).toBeVisible();
            await page.getByRole('button', { name: 'Ablehnen' }).click();

            await expect(page.getByRole('alert')).toBeVisible();
            expect(calls).toEqual([]);
            await expect(page).toHaveURL(/\/firefighting\/test\/claude-verbinden\?/);
        });
    });

    test('gibt es unter service nicht', async ({ page }) => {
        await page.goto('/firefighting/service/claude-verbinden');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeVisible();
    });
});

test.describe('Claude-Verbindungen unter „Angemeldete Geräte“', () => {
    let connections: Record<string, unknown>[] = [];

    test.use({
        api: {
            'GET api/v1/auth/me': { status: 200, body: meBody('user', 'Erika') },
            ...CURRENT_SESSION_ONLY,
            'GET api/v1/auth/claude-connections': () => ({ status: 200, body: { connections } }),
            'DELETE api/v1/auth/claude-connections/7': () => {
                connections = [];
                return { status: 200, body: { deleted: true } };
            },
        },
    });

    test.beforeEach(() => {
        connections = [
            {
                id: 7,
                client_name: 'Claude',
                target: { host: 'claude.ai', local: false },
                access: 'read',
                access_label: 'Lesen',
                created_at: '2026-11-01T08:00:00Z',
                last_used_at: '2026-11-07T09:55:00Z',
            },
        ];
    });

    test('zeigt die Verbindung und trennt sie', async ({ page, apiRequests }) => {
        await page.goto('/firefighting/test/profil');

        const item = page.getByTestId('claude-connection');
        await expect(item).toContainText('Claude (claude.ai)');
        await expect(item).toContainText('Claude: Lesen');
        await expect(item).toContainText('verbunden 01.11.2026');

        await page.getByRole('button', { name: 'Claude (claude.ai) trennen' }).click();

        await expect(page.getByTestId('sessions-message')).toHaveText(
            'Claude (claude.ai) wurde getrennt.',
        );
        await expect(page.getByTestId('claude-connection')).toHaveCount(0);
        expect(
            apiRequests.some(
                (r) => r.method === 'DELETE' && r.path === 'api/v1/auth/claude-connections/7',
            ),
        ).toBe(true);
    });
});
