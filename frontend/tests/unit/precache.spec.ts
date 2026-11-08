import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
    PRECACHED_PAGES,
    offlineFiles,
    precacheFilter,
    type BundleAsset,
    type BundleChunk,
} from '../../build/precache';

const ROOT = resolve(import.meta.dirname, '../..');

function chunk(
    fileName: string,
    options: Partial<Omit<BundleChunk, 'viteMetadata'>> & {
        css?: string[];
        assets?: string[];
    } = {},
): BundleChunk {
    return {
        type: 'chunk',
        fileName,
        isEntry: options.isEntry ?? false,
        facadeModuleId: options.facadeModuleId ?? null,
        imports: options.imports ?? [],
        dynamicImports: options.dynamicImports ?? [],
        viteMetadata: {
            importedCss: new Set(options.css ?? []),
            importedAssets: new Set(options.assets ?? []),
        },
    };
}

function page(name: string): string {
    return `D:/app/frontend/src/pages/${name}.vue`;
}

/** Einstieg mit Router (import() auf alle Seiten), zwei Handy-Seiten, Scanner und eine Seite der Konsole. */
function bundle(): Record<string, BundleChunk | BundleAsset> {
    const items: (BundleChunk | BundleAsset)[] = [
        chunk('assets/index-1.js', {
            isEntry: true,
            imports: ['assets/vue-1.js'],
            dynamicImports: ['assets/SearchPage-1.js', 'assets/UsersPage-1.js'],
            css: ['assets/index-1.css'],
        }),
        chunk('assets/vue-1.js'),
        chunk('assets/SearchPage-1.js', {
            facadeModuleId: page('SearchPage'),
            imports: ['assets/index-1.js', 'assets/DeviceCard-1.js'],
            dynamicImports: ['assets/barcodeReader-1.js'],
            css: ['assets/SearchPage-1.css'],
        }),
        chunk('assets/DeviceCard-1.js', { css: ['assets/DeviceCard-1.css'] }),
        chunk('assets/barcodeReader-1.js', { assets: ['assets/zxing_reader-1.wasm'] }),
        chunk('assets/UsersPage-1.js', {
            facadeModuleId: page('UsersPage'),
            imports: ['assets/index-1.js', 'assets/FormDialog-1.js'],
        }),
        chunk('assets/FormDialog-1.js'),
        { type: 'asset', fileName: 'assets/zxing_reader-1.wasm' },
    ];
    return Object.fromEntries(items.map((item) => [item.fileName, item]));
}

describe('Vorab-Laden des Service Workers (T15)', () => {
    it('lädt den Einstieg und die Offline-Seiten mit allem, was sie nachladen, nicht die Konsole', () => {
        expect([...offlineFiles(bundle(), ['SearchPage'])].sort()).toEqual([
            'assets/DeviceCard-1.css',
            'assets/DeviceCard-1.js',
            'assets/SearchPage-1.css',
            'assets/SearchPage-1.js',
            'assets/barcodeReader-1.js',
            'assets/index-1.css',
            'assets/index-1.js',
            'assets/vue-1.js',
            'assets/zxing_reader-1.wasm',
        ]);
    });

    it('nimmt CSS mit, die kein Teil importiert (von index.html verlinkt, eine Datei)', () => {
        const items = bundle();
        items['assets/style-1.css'] = { type: 'asset', fileName: 'assets/style-1.css' };
        items['assets/UsersPage-1.css'] = { type: 'asset', fileName: 'assets/UsersPage-1.css' };
        items['assets/UsersPage-1.js'] = chunk('assets/UsersPage-1.js', {
            facadeModuleId: page('UsersPage'),
            css: ['assets/UsersPage-1.css'],
        });

        const files = offlineFiles(items, ['SearchPage']);
        expect(files).toContain('assets/style-1.css');
        expect(files).not.toContain('assets/UsersPage-1.css');
    });

    it('folgt einem Teil des Einstiegs, den eine Seite erreicht, auch dessen import()', () => {
        const items = bundle();
        // Ein Teil, den Einstieg und Seite statisch importieren, lädt den Scanner per import().
        items['assets/vue-1.js'] = chunk('assets/vue-1.js', {
            dynamicImports: ['assets/barcodeReader-1.js'],
        });
        const search = items['assets/SearchPage-1.js'] as BundleChunk;
        items['assets/SearchPage-1.js'] = {
            ...search,
            imports: ['assets/vue-1.js'],
            dynamicImports: [],
        };

        expect(offlineFiles(items, ['SearchPage'])).toContain('assets/barcodeReader-1.js');
    });

    it('bricht ab, wenn eine Seite fehlt oder der Einstieg', () => {
        expect(() => offlineFiles(bundle(), ['ProfilePage'])).toThrow(
            'Seite ProfilePage nicht im Bundle',
        );
        const withoutEntry = bundle();
        delete withoutEntry['assets/index-1.js'];
        expect(() => offlineFiles(withoutEntry, ['SearchPage'])).toThrow('kein Einstieg');
    });

    it('filtert die Liste von Workbox; index.html und Schriften immer', () => {
        const plugin = precacheFilter();
        const entry = (url: string) => ({ url, revision: null, size: 1 });
        expect(() => plugin.manifestTransform([entry('index.html')])).toThrow(
            'Plugin nicht gelaufen',
        );

        const generate = plugin.writeBundle as unknown as (
            options: unknown,
            items: unknown,
        ) => void;
        const items = bundle();
        for (const name of PRECACHED_PAGES.filter((p) => p !== 'SearchPage')) {
            items[`assets/${name}-1.js`] = chunk(`assets/${name}-1.js`, {
                facadeModuleId: page(name),
            });
        }
        generate.call({}, {}, items);
        const result = plugin.manifestTransform([
            entry('index.html'),
            entry('assets/source-sans-3-latin-400-normal-1.woff2'),
            entry('assets/SearchPage-1.js'),
            entry('assets/UsersPage-1.js'),
            entry('assets/FormDialog-1.js'),
        ]);
        expect(result).toEqual({
            manifest: [
                entry('index.html'),
                entry('assets/source-sans-3-latin-400-normal-1.woff2'),
                entry('assets/SearchPage-1.js'),
            ],
            warnings: [],
        });
    });

    it('nennt die Handy-Seiten, Startseite, Anmeldung, „nicht gefunden“ und Ziele der Mail-Links', () => {
        const router = readFileSync(resolve(ROOT, 'src/router/index.ts'), 'utf8');
        const pageOf = new Map(
            [
                ...router.matchAll(
                    /name: '([a-z-]+)',\s*component: \(\) => import\('\.\.\/pages\/(\w+)\.vue'\)/g,
                ),
            ].map((m) => [m[1], m[2]]),
        );
        for (const route of [
            'home',
            'login',
            'not-found',
            'search',
            'inspections',
            'profile',
            'device',
            // Öffentliche Ziele der Mail-Links (AppUrls::*_PAGE, registrieren#code=… im QR-Code).
            'password-reset',
            'email-verify',
            'invitation-accept',
            'register',
        ]) {
            expect(PRECACHED_PAGES, route).toContain(pageOf.get(route));
        }
        // Dashboard unter Städten (Startseite der Konsole, landingRoute).
        expect(router).toContain(": () => import('../pages/AdminDashboardPage.vue')");
        for (const name of PRECACHED_PAGES) {
            expect(existsSync(resolve(ROOT, `src/pages/${name}.vue`)), name).toBe(true);
        }
    });
});
