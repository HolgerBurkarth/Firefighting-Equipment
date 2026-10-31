import { describe, expect, it } from 'vitest';
import { deleteLogo, postAccentColor, postLogo } from '../../src/api/appearance';
import { fetchNotifications, parseNotifications, saveNotifications } from '../../src/api/auth';
import { respond } from './support/fetch';

/** Antwort wie AppearanceJson::tenant. */
const TENANT = {
    slug: 'test',
    name: 'Feuerwehr Test',
    accent_color: '#1d3557',
    accent_color_is_default: false,
    logo_url: '/firefighting/test/api/v1/tenant/logo/512?v=abc',
    logo_urls: {
        '512': '/firefighting/test/api/v1/tenant/logo/512?v=abc',
        '192': '/firefighting/test/api/v1/tenant/logo/192?v=abc',
        '180': '/firefighting/test/api/v1/tenant/logo/180?v=abc',
    },
};

describe('Erscheinungsbild (API)', () => {
    it('POST tenant/appearance mit Farbe bzw. null für die Standardfarbe', async () => {
        const server = respond(200, TENANT);
        const result = await postAccentColor('#1d3557', server.fetch);
        expect(result.ok && result.data.accentColor).toBe('#1d3557');
        await postAccentColor(null, server.fetch);
        expect(server.requests.map((r) => [r.method, r.url, r.body])).toEqual([
            [
                'POST',
                expect.stringMatching(/api\/v1\/tenant\/appearance$/),
                { accent_color: '#1d3557' },
            ],
            ['POST', expect.stringMatching(/api\/v1\/tenant\/appearance$/), { accent_color: null }],
        ]);
    });

    it('Kontrast zu gering: Fehler mit details', async () => {
        const server = respond(400, {
            error: 'invalid_request',
            message: 'Weiße Schrift auf #E8B44C erreicht nur …',
            details: { contrast: 1.96, min_contrast: 4.5 },
        });
        const result = await postAccentColor('#e8b44c', server.fetch);
        expect(!result.ok && result.error.details).toEqual({ contrast: 1.96, min_contrast: 4.5 });
    });

    it('POST tenant/logo sendet die Datei als Base64, DELETE mit leerem Objekt', async () => {
        const server = respond(200, TENANT);
        const uploaded = await postLogo('iVBORw0KGgo=', server.fetch);
        expect(uploaded.ok && uploaded.data.logoUrls?.['192']).toBe(TENANT.logo_urls['192']);
        const removed = await deleteLogo(
            respond(200, { ...TENANT, logo_url: null, logo_urls: null }).fetch,
        );
        expect(removed.ok && removed.data.logoUrls).toBeNull();
        expect(server.requests[0]?.body).toEqual({ file: 'iVBORw0KGgo=' });

        const deleting = respond(200, TENANT);
        await deleteLogo(deleting.fetch);
        expect(deleting.requests.map((r) => [r.method, r.body])).toEqual([['DELETE', {}]]);
    });

    it('unerwartete Antwort', async () => {
        const result = await postLogo('x', respond(200, { slug: 'test' }).fetch);
        expect(!result.ok && result.error.code).toBe('invalid_response');
    });
});

describe('Benachrichtigung „Log“ (auth/notifications)', () => {
    it('liest nur echte Wahrheitswerte', () => {
        expect(parseNotifications({ notifications: { device_log: true } })).toEqual({
            deviceLog: true,
        });
        expect(parseNotifications({ notifications: { device_log: 1 } })).toBeNull();
        expect(parseNotifications({ device_log: true })).toBeNull();
        expect(parseNotifications(null)).toBeNull();
    });

    it('GET und POST', async () => {
        const server = respond(200, { notifications: { device_log: true } });
        expect(await fetchNotifications(server.fetch)).toMatchObject({
            ok: true,
            data: { deviceLog: true },
        });
        expect(await saveNotifications(true, server.fetch)).toMatchObject({ ok: true });
        expect(server.requests.map((r) => [r.method, r.url, r.body])).toEqual([
            ['GET', expect.stringMatching(/api\/v1\/auth\/notifications$/), undefined],
            ['POST', expect.stringMatching(/api\/v1\/auth\/notifications$/), { device_log: true }],
        ]);
    });
});
