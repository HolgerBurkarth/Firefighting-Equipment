import { describe, expect, it } from 'vitest';
import { fetchTenant, parseTenant } from '../../src/api/tenant';
import { respond } from './support/fetch';

const TENANT = {
    slug: 'test',
    name: 'Feuerwehr Test',
    accent_color: '#b71c1c',
    accent_color_is_default: true,
    logo_url: null,
    logo_urls: null,
};

describe('parseTenant', () => {
    it('liest eine Stadt ohne Logo', () => {
        expect(parseTenant(TENANT)).toEqual({
            slug: 'test',
            name: 'Feuerwehr Test',
            accentColor: '#b71c1c',
            logoUrls: null,
        });
    });

    it('liest die Logo-Adressen', () => {
        const urls = { '512': '/a?v=1', '192': '/b?v=1', '180': '/c?v=1' };
        expect(parseTenant({ ...TENANT, logo_urls: urls })?.logoUrls).toEqual(urls);
    });

    it.each([null, [], { ...TENANT, name: 5 }, { ...TENANT, logo_urls: { '512': '/a' } }])(
        'lehnt %j ab',
        (json) => {
            expect(parseTenant(json)).toBeNull();
        },
    );
});

describe('fetchTenant', () => {
    it('liefert die Stadt', async () => {
        const result = await fetchTenant(respond(200, TENANT).fetch);
        expect(result.ok && result.data.name).toBe('Feuerwehr Test');
    });

    it('liefert den Fehlercode der API', async () => {
        const result = await fetchTenant(
            respond(404, { error: 'tenant_not_found', message: 'Unbekannt.' }).fetch,
        );
        expect(!result.ok && result.error.code).toBe('tenant_not_found');
    });

    it('meldet Netzwerkfehler', async () => {
        const result = await fetchTenant(() => Promise.reject(new TypeError('offline')));
        expect(!result.ok && result.error.code).toBe('network');
    });
});
