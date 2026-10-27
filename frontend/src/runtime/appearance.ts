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

/**
 * Erscheinungsbild nach GET api/v1/tenant: Stadtfarbe (--accent, theme-color), Titel und – nur mit
 * Logo – Symbol und apple-touch-icon (versionierte Adressen des Logos). Ohne Logo bleiben die
 * Verweise aus index.html auf das Standard-Symbol (api/v1/app-icon/…).
 */
export function applyTenantAppearance(doc: Document, tenant: TenantInfo): void {
    applyAccent(doc.documentElement, tenant.accentColor);
    doc.title = tenant.name + TITLE_SUFFIX;
    if (tenant.logoUrls !== null) {
        setLinkHref(doc, 'icon', tenant.logoUrls['192']);
        setLinkHref(doc, 'apple-touch-icon', tenant.logoUrls['180']);
    }
}

function setLinkHref(doc: Document, rel: string, href: string): void {
    doc.querySelector(`link[rel="${rel}"]`)?.setAttribute('href', href);
}
