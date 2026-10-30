import { readFileSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
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

const DIST = fileURLToPath(new URL('../../dist/', import.meta.url));

/** Alle Dateien des Builds, relativ zu dist/ mit "/". */
function buildFiles(): string[] {
    return readdirSync(DIST, { recursive: true, withFileTypes: true })
        .filter((entry) => entry.isFile())
        .map((entry) => relative(DIST, join(entry.parentPath, entry.name)).replaceAll('\\', '/'));
}

const COMPRESSED = /\.(br|gz)$/;

/** Fassung → Content-Encoding (wie ContentEncoding in PHP). */
const ENCODINGS: Record<string, string> = { br: 'br', gz: 'gzip' };

/** "assets/x.js.br" → Datei "assets/x.js" und Content-Encoding "br". */
function splitVariant(variant: string): { file: string; encoding: string | undefined } {
    const suffix = COMPRESSED.exec(variant)?.[1] ?? '';
    return { file: variant.replace(COMPRESSED, ''), encoding: ENCODINGS[suffix] };
}

test.describe('Jede Datei des Builds', () => {
    test('liefert PHP aus (Namen passend zu FrontendAssets)', async ({ request }) => {
        const files = buildFiles().filter(
            (file) => file !== 'index.html' && !COMPRESSED.test(file),
        );
        expect(files.length).toBeGreaterThan(10);
        for (const file of files) {
            const response = await request.get(`/firefighting/test/${file}`);
            expect(response.status(), file).toBe(200);
        }
    });

    test('komprimierte Fassungen: passend zur Datei, nie direkt abrufbar', async ({ request }) => {
        const variants = buildFiles().filter((file) => COMPRESSED.test(file));
        // Auch sw.js: die Komprimierung läuft nach vite-plugin-pwa.
        expect(variants).toEqual(expect.arrayContaining(['sw.js.br', 'sw.js.gz']));
        for (const variant of variants) {
            const { file, encoding } = splitVariant(variant);
            expect(encoding, variant).toBeDefined();
            const response = await request.get(`/firefighting/test/${file}`, {
                headers: { 'accept-encoding': encoding ?? '' },
            });
            expect(response.status(), variant).toBe(200);
            expect(response.headers()['content-encoding'], variant).toBe(encoding);
            expect(response.headers()['vary'], variant).toBe('Accept-Encoding');
            // Playwright dekomprimiert: der Inhalt muss genau die Datei sein.
            expect((await response.body()).equals(readFileSync(join(DIST, file))), variant).toBe(
                true,
            );

            const direct = await request.get(`/firefighting/test/${variant}`);
            expect(direct.status(), variant).toBe(404);
        }
    });

    test('ohne Accept-Encoding unkomprimiert', async ({ request }) => {
        const response = await request.get('/firefighting/test/sw.js', {
            headers: { 'accept-encoding': 'identity' },
        });
        expect(response.headers()['content-encoding']).toBeUndefined();
        expect(response.headers()['vary']).toBe('Accept-Encoding');
        expect((await response.body()).equals(readFileSync(join(DIST, 'sw.js')))).toBe(true);
    });
});
