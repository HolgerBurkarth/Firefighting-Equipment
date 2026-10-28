import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';
import { appBase } from './build/appBase.ts';
import { buildId, buildInfo } from './build/buildInfo.ts';
import { sanitizeFileName } from './build/fileNames.ts';

/** PHP-Entwicklungsserver: php -S localhost:8000 public/index.php (im Hauptordner). */
const PHP_DEV_SERVER = 'http://localhost:8000';

const BUILD_ID = buildId();

export default defineConfig({
    // Relative Adressen; den Basispfad je Stadt setzt <base href> (vom Server eingesetzt).
    base: './',
    define: { __APP_BUILD_ID__: JSON.stringify(BUILD_ID) },
    plugins: [
        vue(),
        appBase(),
        buildInfo(BUILD_ID),
        VitePWA({
            // Das Manifest liefert PHP je Stadt (manifest.webmanifest); die Registrierung steht in
            // src/runtime/serviceWorker.ts (eigene Datei, kein Inline-Skript wegen der CSP).
            manifest: false,
            injectRegister: false,
            strategies: 'generateSW',
            filename: 'sw.js',
            workbox: {
                globPatterns: ['**/*.{js,css,html,woff2}'],
                navigateFallback: 'index.html',
                navigateFallbackDenylist: [/\/api\//, /\/manifest\.webmanifest$/],
                cleanupOutdatedCaches: true,
                // Eine neue Version wartet, bis die App sie aktiviert (SKIP_WAITING aus
                // src/runtime/appUpdate.ts): nie mitten in einer Eingabe, alte Teile der laufenden
                // App bleiben bis dahin im Cache.
                clientsClaim: true,
                skipWaiting: false,
            },
        }),
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
