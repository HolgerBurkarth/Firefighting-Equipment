import type { TenantInfo } from '../api/tenant';
import { applyAccent } from './accent';

/** Wie ManifestHandler::NAME_FORMAT in PHP („<Stadtname> – Geräteverwaltung“). */
export const TITLE_SUFFIX = ' – Geräteverwaltung';

/**
 * Link auf das Web-App-Manifest der Stadt (relativ zu <base>). Nur unter Städten: Unter service gibt
 * es kein Manifest (die Admin-Konsole des Super-Admins wird nicht installiert).
 */
export function linkManifest(doc: Document): void {
    if (doc.querySelector('link[rel="manifest"]') !== null) {
        return;
    }
    const link = doc.createElement('link');
    link.rel = 'manifest';
    link.href = 'manifest.webmanifest';
    doc.head.append(link);
}

/** Verweise auf das Standard-Symbol wie in index.html (relativ zu <base>). */
export const DEFAULT_ICON_HREFS = {
    icon: 'api/v1/app-icon/192',
    'apple-touch-icon': 'api/v1/app-icon/180',
} as const;

/**
 * Erscheinungsbild nach GET api/v1/tenant (und nach jeder Änderung in den Einstellungen):
 * Stadtfarbe (--accent, theme-color), Titel und Symbol mit apple-touch-icon – mit Logo dessen
 * versionierte Adressen, ohne Logo das Standard-Symbol wie in index.html.
 */
export function applyTenantAppearance(doc: Document, tenant: TenantInfo): void {
    applyAccent(doc.documentElement, tenant.accentColor);
    doc.title = tenant.name + TITLE_SUFFIX;
    setLinkHref(doc, 'icon', tenant.logoUrls?.['192'] ?? DEFAULT_ICON_HREFS.icon);
    setLinkHref(
        doc,
        'apple-touch-icon',
        tenant.logoUrls?.['180'] ?? DEFAULT_ICON_HREFS['apple-touch-icon'],
    );
}

function setLinkHref(doc: Document, rel: string, href: string): void {
    doc.querySelector(`link[rel="${rel}"]`)?.setAttribute('href', href);
}
