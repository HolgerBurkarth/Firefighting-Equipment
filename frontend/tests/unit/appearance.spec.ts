import { beforeEach, describe, expect, it } from 'vitest';
import type { TenantInfo } from '../../src/api/tenant';
import { applyTenantAppearance, linkManifest, TITLE_SUFFIX } from '../../src/runtime/appearance';

const TENANT: TenantInfo = {
    slug: 'test',
    name: 'Feuerwehr Test',
    accentColor: '#1d3557',
    logoUrls: null,
};

const LOGO_URLS = {
    '512': '/firefighting/test/api/v1/tenant/logo/512?v=abc',
    '192': '/firefighting/test/api/v1/tenant/logo/192?v=abc',
    '180': '/firefighting/test/api/v1/tenant/logo/180?v=abc',
};

function href(rel: string): string | null | undefined {
    return document.querySelector(`link[rel="${rel}"]`)?.getAttribute('href');
}

describe('Erscheinungsbild der Stadt', () => {
    beforeEach(() => {
        document.head.innerHTML =
            '<meta name="theme-color" content="#b71c1c">' +
            '<title>Geräteverwaltung</title>' +
            '<link rel="icon" type="image/png" href="api/v1/app-icon/192">' +
            '<link rel="apple-touch-icon" href="api/v1/app-icon/180">';
        document.documentElement.removeAttribute('style');
    });

    it('setzt Stadtfarbe, theme-color und Titel', () => {
        applyTenantAppearance(document, TENANT);

        expect(document.documentElement.style.getPropertyValue('--accent')).toBe('#1d3557');
        expect(document.querySelector('meta[name="theme-color"]')?.getAttribute('content')).toBe(
            '#1d3557',
        );
        expect(document.title).toBe('Feuerwehr Test – Geräteverwaltung');
    });

    it('lässt ohne Logo das Standard-Symbol stehen', () => {
        applyTenantAppearance(document, TENANT);

        expect(href('icon')).toBe('api/v1/app-icon/192');
        expect(href('apple-touch-icon')).toBe('api/v1/app-icon/180');
    });

    it('verwendet mit Logo die versionierten Logo-Adressen', () => {
        applyTenantAppearance(document, { ...TENANT, logoUrls: LOGO_URLS });

        expect(href('icon')).toBe(LOGO_URLS['192']);
        expect(href('apple-touch-icon')).toBe(LOGO_URLS['180']);
    });

    it('Titel wie der Name im Manifest (ManifestHandler::NAME_FORMAT)', () => {
        expect(TITLE_SUFFIX).toBe(' – Geräteverwaltung');
    });
});

describe('linkManifest', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
    });

    it('verweist relativ auf manifest.webmanifest', () => {
        linkManifest(document);

        expect(href('manifest')).toBe('manifest.webmanifest');
    });

    it('legt den Link nur einmal an', () => {
        linkManifest(document);
        linkManifest(document);

        expect(document.querySelectorAll('link[rel="manifest"]')).toHaveLength(1);
    });
});
