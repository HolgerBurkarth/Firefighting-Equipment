import type { BrowserContext, Page, Request } from '@playwright/test';

/** Einzige erlaubte Basisadresse: die Test-Stadt (CLAUDE.md, "Autonomer Ablauf", Schritt 5). */
export const BASE_URL = 'https://www.prodad.de/firefighting/test/';
export const HOST = 'www.prodad.de';
export const PATH_PREFIX = '/firefighting/test/';

/** Konten, mit denen der Lauf arbeiten darf (Kennungen der Test-Stadt, keine Zugangsdaten). */
export const ACCOUNT_IDS = { user1: 5, user2: 7 } as const;

/** Nur diese Ausrüstung darf der Lauf anfassen. */
export const IDENTIFIER = /^M9\d$/;

export class GuardViolation extends Error {}

/** Ist die Adresse erlaubt? Fremde Hosts sind Sache des Browsers (Schriften u. Ä. gibt es nicht). */
export function isAllowedUrl(url: string): boolean {
    let parsed: URL;
    try {
        parsed = new URL(url);
    } catch {
        return false;
    }
    if (
        parsed.protocol === 'data:' ||
        parsed.protocol === 'blob:' ||
        parsed.protocol === 'about:'
    ) {
        return true;
    }
    return (
        parsed.protocol === 'https:' &&
        parsed.hostname === HOST &&
        parsed.pathname.startsWith(PATH_PREFIX)
    );
}

/**
 * Ziele einer ändernden Anfrage: IDs der Ausrüstung im Pfad bzw. im Tausch. `null` = die Anfrage
 * nennt keine Ausrüstung (z. B. Abmelden, Anmelden).
 */
export function deviceIdsOfRequest(
    method: string,
    pathname: string,
    body: unknown,
): number[] | null {
    if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') {
        return null;
    }
    const api = pathname.startsWith(`${PATH_PREFIX}api/v1/`)
        ? pathname.slice(`${PATH_PREFIX}api/v1/`.length)
        : null;
    if (api === null) {
        return null;
    }
    const single = /^devices\/(\d+)(\/|$)/.exec(api);
    if (single?.[1]) {
        return [Number(single[1])];
    }
    if (api === 'devices/swap') {
        const record = body as { out?: { id?: unknown }; in?: { id?: unknown } } | null;
        const ids = [record?.out?.id, record?.in?.id];
        return ids.every((id) => typeof id === 'number') ? ids : [];
    }
    if (api.startsWith('devices')) {
        // Sammelaktionen, Import u. Ä.: nie.
        return [];
    }
    return null;
}

function parseBody(request: Request): unknown {
    try {
        return request.postDataJSON();
    } catch {
        return null;
    }
}

/**
 * Sicherung für einen Kontext: Alles außerhalb der Test-Stadt wird abgebrochen und gemeldet;
 * ändernde Anfragen an Ausrüstung außerhalb von `allowed` (M90–M99, vom Lauf ermittelt) ebenso.
 * Gibt die gesammelten Verstöße zurück; der Lauf prüft sie am Ende.
 */
export async function installGuard(
    context: BrowserContext,
    allowed: ReadonlySet<number>,
    violations: string[],
): Promise<void> {
    await context.route('**/*', async (route) => {
        const request = route.request();
        if (!isAllowedUrl(request.url())) {
            violations.push(`Fremde Adresse blockiert: ${new URL(request.url()).origin}`);
            await route.abort();
            return;
        }
        const ids = deviceIdsOfRequest(
            request.method(),
            new URL(request.url()).pathname,
            parseBody(request),
        );
        if (ids !== null && (ids.length === 0 || ids.some((id) => !allowed.has(id)))) {
            violations.push(
                `Ändernde Anfrage an nicht erlaubte Ausrüstung blockiert: ${request.method()} ${new URL(request.url()).pathname}`,
            );
            await route.abort();
            return;
        }
        await route.fallback();
    });
}

/** Nur Seiten der Test-Stadt öffnen. */
export async function open(page: Page, path: string): Promise<void> {
    const url = new URL(path, BASE_URL).toString();
    if (!isAllowedUrl(url)) {
        throw new GuardViolation('Adresse außerhalb der Test-Stadt.');
    }
    await page.goto(url);
}
