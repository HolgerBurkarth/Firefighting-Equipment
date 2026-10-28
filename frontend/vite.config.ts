import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';
import { appBase } from './build/appBase.ts';

/** PHP-Entwicklungsserver: php -S localhost:8000 public/index.php (im Hauptordner). */
const PHP_DEV_SERVER = 'http://localhost:8000';

/**
 * Kennung des Builds (src/runtime/build.ts): FF_BUILD_ID (z. B. zweiter Build der Browser-Tests),
 * sonst der Commit in GitHub Actions, sonst "dev". Gleicher Stand ergibt denselben Build.
 */
function buildId(): string {
    const id = process.env['FF_BUILD_ID'] ?? process.env['GITHUB_SHA']?.slice(0, 12) ?? 'dev';
    if (!/^[A-Za-z0-9._-]{1,40}$/.test(id)) {
        throw new Error('FF_BUILD_ID: nur A-Z, a-z, 0-9, Punkt, Unterstrich, Bindestrich (1-40)');
    }
    return id;
}

export default defineConfig({
    // Relative Adressen; den Basispfad je Stadt setzt <base href> (vom Server eingesetzt).
    base: './',
    define: { __APP_BUILD_ID__: JSON.stringify(buildId()) },
    plugins: [
        vue(),
        appBase(),
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
    },
});
