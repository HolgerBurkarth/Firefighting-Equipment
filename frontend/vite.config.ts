import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';
import { appBase } from './build/appBase.ts';
import { buildId, buildInfo } from './build/buildInfo.ts';
import { compressBuild } from './build/compress.ts';
import { sanitizeFileName } from './build/fileNames.ts';
import { NAVIGATE_FALLBACK_DENYLIST } from './build/navigateFallback.ts';
import {
    precacheFilter,
    RUNTIME_ASSET_CACHE,
    RUNTIME_ASSET_MAX_ENTRIES,
} from './build/precache.ts';

/** PHP-Entwicklungsserver: php -S localhost:8000 public/index.php (im Hauptordner). */
const PHP_DEV_SERVER = 'http://localhost:8000';

const BUILD_ID = buildId();

/** Vorab-Laden nur für die Seiten, die offline gehen müssen (build/precache.ts, T15). */
const precache = precacheFilter();

export default defineConfig({
    // Relative Adressen; den Basispfad je Stadt setzt <base href> (vom Server eingesetzt).
    base: './',
    define: { __APP_BUILD_ID__: JSON.stringify(BUILD_ID) },
    plugins: [
        vue(),
        appBase(),
        buildInfo(BUILD_ID),
        precache,
        VitePWA({
            // Das Manifest liefert PHP je Stadt (manifest.webmanifest); die Registrierung steht in
            // src/runtime/serviceWorker.ts (eigene Datei, kein Inline-Skript wegen der CSP).
            manifest: false,
            injectRegister: false,
            strategies: 'generateSW',
            filename: 'sw.js',
            workbox: {
                // Mit dem Lesemodul des Kamera-Scanners (wasm, rund 950 KB): Scannen geht auch
                // offline (KONZEPT.md, "Offline-Modus – Entscheidungen", 56).
                // Davon vorab nur die Handy-Seiten mit allem, was sie brauchen (precacheFilter).
                globPatterns: ['**/*.{js,css,html,woff2,wasm}'],
                manifestTransforms: [precache.manifestTransform],
                // Die übrigen Dateien aus assets/ (Seiten der Konsole …) beim ersten Laden ablegen:
                // Namen mit Hash, also unveränderlich; nur 200er (eine fehlende alte Datei kommt als
                // 404 und wird nie abgelegt), ältere fallen nach RUNTIME_ASSET_MAX_ENTRIES weg.
                runtimeCaching: [
                    {
                        urlPattern: /\/assets\/[A-Za-z0-9][A-Za-z0-9_.-]*\.(?:js|css|woff2|wasm)$/,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: RUNTIME_ASSET_CACHE,
                            cacheableResponse: { statuses: [200] },
                            expiration: {
                                maxEntries: RUNTIME_ASSET_MAX_ENTRIES,
                                purgeOnQuotaError: true,
                            },
                        },
                    },
                ],
                navigateFallback: 'index.html',
                navigateFallbackDenylist: [...NAVIGATE_FALLBACK_DENYLIST],
                cleanupOutdatedCaches: true,
                // Eine neue Version wartet, bis die App sie aktiviert (SKIP_WAITING aus
                // src/runtime/appUpdate.ts): nie mitten in einer Eingabe, alte Teile der laufenden
                // App bleiben bis dahin im Cache.
                clientsClaim: true,
                skipWaiting: false,
            },
        }),
        // Zuletzt: .br und .gz neben jede Datei (auch sw.js), PHP wählt die Fassung.
        compressBuild(),
    ],
    server: {
        port: 5173,
        strictPort: true,
        proxy: {
            '^/firefighting/[a-z0-9-]+/(api/|manifest\\.webmanifest)': PHP_DEV_SERVER,
        },
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        target: ['es2022', 'safari16', 'firefox115', 'chrome111'],
        // Namen wie FrontendAssets (PHP) sie ausliefert; per Browser-Test für jede Datei geprüft.
        rolldownOptions: { output: { sanitizeFileName } },
    },
});
