import { applyAccent } from './accent';

/**
 * Startbild in index.html (Plan Phase 1, Schritt 1.7d „Schnellstart“, Stil in src/styles/splash.css):
 * Stand des Ladebalkens, Name und Farbe der Stadt aus dem letzten Besuch, Entfernen, sobald die
 * erste Seite steht. Ohne Startbild (z. B. in Tests) tun alle Funktionen nichts.
 */

/** Schritte nach dem Laden der App; vorher (`start`) läuft der Balken ohne Stand. */
export type SplashStep = 'app' | 'session' | 'data';

export const SPLASH_STEPS: Readonly<Record<SplashStep, { label: string; value: number }>> = {
    app: { label: 'App wird geladen …', value: 33 },
    session: { label: 'Anmeldung wird geprüft …', value: 66 },
    data: { label: 'Ausrüstung wird geladen …', value: 92 },
};

/** Zeitpunkte für das Messskript (performance.mark): erste Seite, „Suchen“ mit Geräten. */
export const FIRST_PAGE_MARK = 'ff:first-page';
export const SEARCH_DATA_MARK = 'ff:search-data';

/** Setzt die Marke `name`, nur beim ersten Mal je Seitenaufruf. */
export function markStartup(name: string): void {
    try {
        if (performance.getEntriesByName(name, 'mark').length === 0) {
            performance.mark(name);
        }
    } catch {
        // Ohne Performance-API nur ohne Marke.
    }
}

const SPLASH_ID = 'splash';

/** Name und Farbe der Stadt je Bereich (localStorage), damit das Startbild sie kennt. */
export function rememberedTenantKey(area: string): string {
    return `ff-tenant:${area}`;
}

export interface RememberedTenant {
    readonly name: string;
    readonly accentColor: string | null;
}

interface TenantStorage {
    getItem(key: string): string | null;
    setItem(key: string, value: string): void;
    removeItem(key: string): void;
}

/** Merkt sich Name und Farbe der Stadt nach jeder Antwort von api/v1/tenant. */
export function rememberTenant(
    storage: TenantStorage | null,
    area: string,
    tenant: RememberedTenant | null,
): void {
    try {
        if (tenant === null) {
            storage?.removeItem(rememberedTenantKey(area));
        } else {
            storage?.setItem(
                rememberedTenantKey(area),
                JSON.stringify({ name: tenant.name, accent_color: tenant.accentColor }),
            );
        }
    } catch {
        // Ohne Speicher zeigt das Startbild „Feuerwehr“ in der Standardfarbe.
    }
}

export function readRememberedTenant(
    storage: TenantStorage | null,
    area: string,
): RememberedTenant | null {
    try {
        const value: unknown = JSON.parse(storage?.getItem(rememberedTenantKey(area)) ?? 'null');
        if (typeof value !== 'object' || value === null) {
            return null;
        }
        const { name, accent_color: accent } = value as Record<string, unknown>;
        if (
            typeof name !== 'string' ||
            name === '' ||
            (accent !== null && typeof accent !== 'string')
        ) {
            return null;
        }
        return { name, accentColor: accent };
    } catch {
        return null;
    }
}

function splash(doc: Document): HTMLElement | null {
    return doc.getElementById(SPLASH_ID);
}

/**
 * Als Erstes in main.ts: Name der Stadt aus dem letzten Besuch ins Startbild (unter service
 * „Super-Admin“), ihre Farbe als --accent (per CSSOM, applyAccent). Beim ersten Besuch bleiben
 * „Feuerwehr“ und die Standardfarbe.
 */
export function showRememberedTenant(
    doc: Document,
    storage: TenantStorage | null,
    area: string,
    isService: boolean,
): void {
    const title = splash(doc)?.querySelector('[data-splash-title]');
    if (isService) {
        if (title) {
            title.textContent = 'Super-Admin';
        }
        return;
    }
    const tenant = readRememberedTenant(storage, area);
    if (tenant === null) {
        return;
    }
    applyAccent(doc.documentElement, tenant.accentColor);
    if (title) {
        title.textContent = tenant.name;
    }
}

/** Setzt den Stand des Ladebalkens (Attribut, Text und aria-valuenow). */
export function setSplashStep(doc: Document, step: SplashStep): void {
    const element = splash(doc);
    if (element === null) {
        return;
    }
    const { label, value } = SPLASH_STEPS[step];
    element.dataset['step'] = step;
    element.querySelector('[role="progressbar"]')?.setAttribute('aria-valuenow', String(value));
    const text = element.querySelector('[data-splash-label]');
    if (text) {
        text.textContent = label;
    }
}

/** Entfernt das Startbild (einmal) und setzt die Marke „erste Seite“. */
export function removeSplash(doc: Document): void {
    const element = splash(doc);
    if (element === null) {
        return;
    }
    element.remove();
    markStartup(FIRST_PAGE_MARK);
}
