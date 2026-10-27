import { test as base, expect, type Page, type Route } from '@playwright/test';

/** Antwort von GET api/v1/tenant wie im Backend (TenantHandler). */
export interface TenantResponse {
    readonly status: number;
    readonly body: Record<string, unknown>;
}

export const TEST_TENANT: TenantResponse = {
    status: 200,
    body: {
        slug: 'test',
        name: 'Feuerwehr Test',
        accent_color: '#b71c1c',
        accent_color_is_default: true,
        logo_url: null,
        logo_urls: null,
    },
};

/** 1 × 1 px, transparent (für die Symbole aus api/v1/app-icon/…). */
const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    'base64',
);

interface Options {
    /** Antwort für GET api/v1/tenant. */
    tenant: TenantResponse;
    /**
     * Erwarteter Konsolenfehler (z. B. die Meldung des Browsers zu einer 404-Antwort). Gilt nie
     * für CSP-Verletzungen oder unbehandelte Fehler im Skript.
     */
    allowedConsoleError: RegExp | null;
}

interface Fixtures {
    /** Aufgerufene API-Pfade relativ zum Bereich, z. B. "api/v1/tenant". */
    apiCalls: string[];
    guard: undefined;
}

async function fulfillApi(
    route: Route,
    tenant: TenantResponse,
    problems: string[],
): Promise<string> {
    const path = new URL(route.request().url()).pathname.replace(
        /^\/firefighting\/[a-z0-9-]+\//,
        '',
    );
    if (path === 'api/v1/tenant') {
        await route.fulfill({ status: tenant.status, json: tenant.body });
    } else if (/^api\/v1\/(app-icon|tenant\/logo)\/(512|192|180)$/.test(path)) {
        await route.fulfill({ status: 200, contentType: 'image/png', body: PNG });
    } else if (path === 'manifest.webmanifest') {
        await route.fulfill({
            status: 200,
            contentType: 'application/manifest+json; charset=utf-8',
            json: {
                name: 'Feuerwehr Test – Geräteverwaltung',
                start_url: '.',
                display: 'standalone',
            },
        });
    } else {
        problems.push(`Unerwartete Anfrage: ${route.request().method()} ${path}`);
        await route.fulfill({ status: 404, json: { error: 'not_found', message: 'Test.' } });
    }
    return path;
}

async function watchProblems(
    page: Page,
    problems: string[],
    allowed: RegExp | null,
): Promise<void> {
    page.on('console', (message) => {
        const text = message.text();
        if (message.type() === 'error' && allowed?.test(text) !== true) {
            problems.push(`Konsolenfehler: ${text}`);
        }
    });
    page.on('pageerror', (error) => {
        problems.push(`Skriptfehler: ${error.message}`);
    });
    await page.exposeFunction('__ffReportCsp', (violation: string) => {
        problems.push(`CSP-Verletzung: ${violation}`);
    });
    await page.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
            const report = (window as unknown as { __ffReportCsp: (v: string) => void })
                .__ffReportCsp;
            report(`${event.effectiveDirective} ${event.blockedURI}`);
        });
    });
}

export const test = base.extend<Options & Fixtures>({
    tenant: [TEST_TENANT, { option: true }],
    allowedConsoleError: [null, { option: true }],
    apiCalls: async ({}, use) => {
        await use([]);
    },
    guard: [
        async ({ page, tenant, allowedConsoleError, apiCalls }, use) => {
            const problems: string[] = [];
            await watchProblems(page, problems, allowedConsoleError);
            await page.route(
                /\/firefighting\/[a-z0-9-]+\/(api\/|manifest\.webmanifest)/,
                async (route) => {
                    apiCalls.push(await fulfillApi(route, tenant, problems));
                },
            );
            await use(undefined);
            expect(problems, 'Konsolenfehler, CSP-Verletzungen oder unerwartete Anfragen').toEqual(
                [],
            );
        },
        { auto: true },
    ],
});

export { expect };
