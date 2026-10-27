import { expect, test } from './fixtures';

/** Auslieferung durch PHP (FrontendHandler), ohne Browser-Seite geprüft. */
test.describe('Auslieferung durch PHP', () => {
    test('App-Seite mit Basispfad, CSP und no-cache', async ({ request }) => {
        const response = await request.get('/firefighting/test/geraete/12');
        expect(response.status()).toBe(200);
        const headers = response.headers();
        expect(headers['content-type']).toBe('text/html; charset=utf-8');
        expect(headers['cache-control']).toBe('no-cache');
        expect(headers['content-security-policy']).toContain("script-src 'self'");
        expect(headers['content-security-policy']).not.toContain('unsafe-inline');
        expect(headers['x-frame-options']).toBe('DENY');
        expect(headers['set-cookie']).toBeUndefined();
        expect(await response.text()).toContain('<base href="/firefighting/test/" />');
    });

    test('Skripte aus assets/ dauerhaft zwischenspeicherbar', async ({ request }) => {
        const html = await (await request.get('/firefighting/test/')).text();
        const script = /src="\.\/(assets\/index-[^"]+\.js)"/.exec(html)?.[1];
        expect(script).toBeDefined();

        const response = await request.get(`/firefighting/test/${script ?? ''}`);
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toBe('text/javascript; charset=utf-8');
        expect(response.headers()['cache-control']).toBe('public, max-age=31536000, immutable');
    });

    test('Service Worker mit no-cache, fehlende Dateien 404', async ({ request }) => {
        const sw = await request.get('/firefighting/test/sw.js');
        expect(sw.status()).toBe(200);
        expect(sw.headers()['cache-control']).toBe('no-cache');

        const missing = await request.get('/firefighting/test/assets/gibt-es-nicht.js');
        expect(missing.status()).toBe(404);
        expect(await missing.json()).toMatchObject({ error: 'not_found' });
    });

    test('nur GET bekommt die App-Seite', async ({ request }) => {
        const post = await request.post('/firefighting/test/anmelden', { data: {} });
        expect(post.status()).toBe(404);
        expect(await post.json()).toMatchObject({ error: 'not_found' });

        const head = await request.head('/firefighting/test/anmelden');
        expect(head.status()).toBe(404);

        const api = await request.get('/firefighting/test/api/v1/gibt-es-nicht');
        expect(api.status()).toBe(404);
        expect(api.headers()['content-type']).toBe('application/json; charset=utf-8');
    });
});

test.describe('Symbole und Manifest durch PHP', () => {
    test('Standard-Symbol auch unter service, Manifest nur unter Städten', async ({ request }) => {
        const icon = await request.get('/firefighting/service/api/v1/app-icon/192');
        expect(icon.status()).toBe(200);
        expect(icon.headers()['content-type']).toBe('image/png');

        const manifest = await request.get('/firefighting/service/manifest.webmanifest');
        expect(manifest.status()).toBe(404);
        expect(await manifest.json()).toMatchObject({ error: 'not_found' });
    });
});
