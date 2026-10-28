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

/** Antwort einer simulierten API-Route; `abort` simuliert einen Netzwerkfehler. */
export type MockResponse =
    | {
          readonly status: number;
          readonly body?: unknown;
          readonly headers?: Readonly<Record<string, string>>;
      }
    | 'abort';

/** Anfrage an eine simulierte Route (Bereich, JSON-Body, vollständige Adresse). */
export interface MockRequest {
    readonly area: string;
    readonly method: string;
    readonly path: string;
    readonly url: string;
    readonly body: unknown;
}

/**
 * Simulierte API-Routen, Schlüssel "METHODE pfad" relativ zum Bereich, z. B.
 * "POST api/v1/auth/login". Eine Funktion kann je Anfrage anders antworten.
 */
export type ApiMocks = Readonly<
    Record<string, MockResponse | ((request: MockRequest) => MockResponse)>
>;

/** Antwort von auth/me bzw. auth/login wie AuthJson::me in PHP. */
export function meBody(
    role: 'user' | 'admin' | 'superadmin',
    displayName = 'Anna',
): Record<string, unknown> {
    return {
        user: {
            id: 7,
            email: 'anna@example.org',
            name: 'Anna Beispiel',
            display_name: displayName,
            role,
            protected: false,
            notifications: { device_log: false },
        },
        tenant: role === 'superadmin' ? null : { slug: 'test', name: 'Feuerwehr Test' },
        session: { id: 3, device_label: 'Chrome auf Windows', current: true },
    };
}

export const NOT_AUTHENTICATED: MockResponse = {
    status: 401,
    body: { error: 'not_authenticated', message: 'Bitte melden Sie sich an.' },
};

export const DATABASE_UNAVAILABLE: MockResponse = {
    status: 503,
    body: { error: 'database_unavailable', message: 'Die Datenbank ist nicht erreichbar.' },
};

/** Meldung der Browser zu 4xx/5xx-Antworten (Chromium, WebKit; Firefox meldet sie nicht). */
export const HTTP_ERROR_CONSOLE = /status of [45]\d\d|Failed to load resource/;

/** Meldung der Browser zu abgebrochenen Anfragen (route.abort) und zu 4xx/5xx-Antworten. */
export const NETWORK_ERROR_CONSOLE =
    /status of [45]\d\d|Failed to load resource|ERR_INTERNET_DISCONNECTED|NetworkError|network connection/i;

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
    /** Weitere simulierte API-Routen (jede andere Anfrage lässt den Test scheitern). */
    api: ApiMocks;
}

interface Fixtures {
    /** Aufgerufene API-Pfade relativ zum Bereich, z. B. "api/v1/tenant". */
    apiCalls: string[];
    /** Alle Anfragen an simulierte Routen aus `api`, mit Body. */
    apiRequests: MockRequest[];
    guard: undefined;
}

function requestBody(route: Route): unknown {
    const text = route.request().postData();
    if (text === null) {
        return undefined;
    }
    try {
        return JSON.parse(text) as unknown;
    } catch {
        return text;
    }
}

async function fulfillMock(route: Route, response: MockResponse): Promise<void> {
    if (response === 'abort') {
        await route.abort('internetdisconnected');
        return;
    }
    await route.fulfill({
        status: response.status,
        headers: { 'Cache-Control': 'no-store', ...response.headers },
        contentType: 'application/json; charset=utf-8',
        body: response.body === undefined ? '' : JSON.stringify(response.body),
    });
}

async function fulfillApi(
    route: Route,
    tenant: TenantResponse,
    api: ApiMocks,
    apiRequests: MockRequest[],
    problems: string[],
): Promise<string> {
    // Wie die Routen in PHP (Routes::service/tenant): Unter service gibt es nur das Standard-Symbol,
    // nicht Stadt, Logo oder Manifest.
    const url = route.request().url();
    const match = /^\/firefighting\/([a-z0-9-]+)\/(.*)$/.exec(new URL(url).pathname);
    const area = match?.[1] ?? '';
    const isService = area === 'service';
    const path = match?.[2] ?? '';
    const method = route.request().method();
    const mock = api[`${method} ${path}`];
    // Jeder Aufruf der App (über apiRequest) trägt die Vertragsnummer; Bilder und das Manifest
    // lädt der Browser selbst, ohne den Header.
    const byApp = path.startsWith('api/') && !/^api\/v1\/(app-icon|tenant\/logo)\//.test(path);
    if (byApp && route.request().headers()['x-client-api'] !== '1') {
        problems.push(`Ohne X-Client-Api: 1: ${method} ${path}`);
    }
    if (mock !== undefined) {
        const request = { area, method, path, url, body: requestBody(route) };
        apiRequests.push(request);
        await fulfillMock(route, typeof mock === 'function' ? mock(request) : mock);
    } else if (path === 'api/v1/tenant' && !isService) {
        await route.fulfill({ status: tenant.status, json: tenant.body });
    } else if (/^api\/v1\/app-icon\/(512|192|180)$/.test(path)) {
        await route.fulfill({ status: 200, contentType: 'image/png', body: PNG });
    } else if (/^api\/v1\/tenant\/logo\/(512|192|180)$/.test(path) && !isService) {
        await route.fulfill({ status: 200, contentType: 'image/png', body: PNG });
    } else if (path === 'manifest.webmanifest' && !isService) {
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
        problems.push(`Unerwartete Anfrage: ${method} ${path}`);
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
    api: [{}, { option: true }],
    apiCalls: async ({}, use) => {
        await use([]);
    },
    apiRequests: async ({}, use) => {
        await use([]);
    },
    guard: [
        async ({ page, tenant, allowedConsoleError, api, apiCalls, apiRequests }, use) => {
            const problems: string[] = [];
            await watchProblems(page, problems, allowedConsoleError);
            await page.route(
                /\/firefighting\/[a-z0-9-]+\/(api\/|manifest\.webmanifest)/,
                async (route) => {
                    apiCalls.push(await fulfillApi(route, tenant, api, apiRequests, problems));
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
