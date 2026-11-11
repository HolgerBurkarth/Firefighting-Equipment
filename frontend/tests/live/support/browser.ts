import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Page, Response } from '@playwright/test';
import { ACCOUNT_IDS, BASE_URL, open } from './guard';
import type { LiveAccount } from './env';

const CLIENT_API = (
    JSON.parse(
        readFileSync(
            join(dirname(fileURLToPath(import.meta.url)), '../../../../resources/client-api.json'),
            'utf8',
        ),
    ) as { client_api: number }
).client_api;

/** Gescheiterte Anmeldung o. Ä.: der Lauf bricht sofort ab, es gibt keinen zweiten Versuch. */
export class FatalLiveError extends Error {}

export interface LiveDevice {
    id: number;
    identifier: string;
    version: number;
    status: string;
    location: { id: number; name: string; path: string };
    device_type: { id: number; name: string; single_use?: boolean };
}

/** API-Aufruf mit dem Cookie der Seite (derselbe Weg wie `apiRequest` der App). */
// eslint-disable-next-line @typescript-eslint/no-unnecessary-type-parameters -- T beschreibt die erwartete Antwort des Aufrufers
export async function api<T = unknown>(
    page: Page,
    method: 'GET' | 'POST',
    path: string,
    body?: unknown,
): Promise<{ status: number; data: T }> {
    return page.evaluate(
        async ({ method, path, body, clientApi }) => {
            const response = await fetch(new URL(`api/v1/${path}`, document.baseURI), {
                method,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Client-Api': String(clientApi) },
                ...(method === 'POST' ? { body: JSON.stringify(body ?? {}) } : {}),
            });
            const text = await response.text();
            let data: unknown = null;
            try {
                data = JSON.parse(text);
            } catch {
                data = null;
            }
            return { status: response.status, data: data as T };
        },
        { method, path, body, clientApi: CLIENT_API },
    );
}

/**
 * Meldet sich über die Anmeldeseite an. Kein Trace, kein Screenshot, keine Ausgabe der Eingaben.
 * Scheitert es, bricht der Lauf ab (kein zweiter Versuch: die Anmeldung hat eine Bremse).
 */
export async function login(
    page: Page,
    account: LiveAccount,
    expectedId: (typeof ACCOUNT_IDS)[keyof typeof ACCOUNT_IDS],
    label: string,
): Promise<void> {
    await open(page, 'anmelden');
    await page.getByLabel('E-Mail-Adresse').fill(account.email);
    await page.getByLabel('Passwort', { exact: true }).fill(account.password);
    await page.getByTestId('login-submit').click();
    try {
        await page.waitForURL((url) => !url.pathname.endsWith('/anmelden'), { timeout: 20_000 });
    } catch {
        throw new FatalLiveError(
            `Anmeldung von ${label} fehlgeschlagen – Lauf abgebrochen (kein zweiter Versuch).`,
        );
    }
    const me = await api<{ user?: { id?: number } }>(page, 'GET', 'auth/me');
    if (me.data.user?.id !== expectedId) {
        throw new FatalLiveError(
            `${label} ist nicht das erwartete Konto #${String(expectedId)} – Lauf abgebrochen.`,
        );
    }
}

export interface Recorded {
    method: string;
    path: string;
    status: number;
    request: Record<string, unknown> | null;
    response: Promise<Record<string, unknown> | null>;
}

/** Zeichnet alle Antworten der API auf (Methode, Pfad, Status, Body der Anfrage und Antwort). */
export function record(page: Page): Recorded[] {
    const list: Recorded[] = [];
    page.on('response', (response: Response) => {
        const url = new URL(response.url());
        const marker = `${BASE_URL.replace('https://www.prodad.de', '')}api/v1/`;
        if (!url.pathname.startsWith(marker)) {
            return;
        }
        let request: Record<string, unknown> | null = null;
        try {
            request = response.request().postDataJSON() as Record<string, unknown> | null;
        } catch {
            request = null;
        }
        list.push({
            method: response.request().method(),
            path: url.pathname.slice(marker.length),
            status: response.status(),
            request,
            response: response
                .json()
                .then((json) => json as Record<string, unknown>)
                .catch(() => null),
        });
    });
    return list;
}

/** Geräte der Kopie (IndexedDB `ff-offline`, `snapshots`, Schlüssel `test:<konto>`), sonst null. */
export async function storedDeviceIds(page: Page, accountId: number): Promise<number[] | null> {
    return page.evaluate(
        (key) =>
            new Promise<number[] | null>((resolve) => {
                const open = indexedDB.open('ff-offline');
                open.onupgradeneeded = () => {
                    open.transaction?.abort();
                };
                open.onerror = () => {
                    resolve(null);
                };
                open.onsuccess = () => {
                    const db = open.result;
                    if (!db.objectStoreNames.contains('snapshots')) {
                        db.close();
                        resolve(null);
                        return;
                    }
                    const get = db.transaction('snapshots').objectStore('snapshots').get(key);
                    get.onsuccess = () => {
                        const value = get.result as { devices?: { id: number }[] } | undefined;
                        db.close();
                        resolve(value?.devices?.map((device) => device.id) ?? null);
                    };
                    get.onerror = () => {
                        db.close();
                        resolve(null);
                    };
                };
            }),
        `test:${String(accountId)}`,
    );
}

/** Statusknöpfe der Umbuchen-Seite (Bezeichnungen wie `STATUS_LABELS`). */
export const STATUS_LABEL: Record<string, string> = {
    ready: 'einsatzbereit',
    maintenance: 'in Wartung',
    defective: 'defekt',
};

/** Ein anderer Status als der aktuelle (zwischen einsatzbereit und in Wartung). */
export function otherStatus(current: string): 'ready' | 'maintenance' {
    return current === 'ready' ? 'maintenance' : 'ready';
}
