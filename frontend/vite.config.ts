import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';
import { appBase } from './build/appBase.ts';

/** PHP-Entwicklungsserver: php -S localhost:8000 public/index.php (im Hauptordner). */
const PHP_DEV_SERVER = 'http://localhost:8000';

export default defineConfig({
    // Relative Adressen; den Basispfad je Stadt setzt <base href> (vom Server eingesetzt).
    base: './',
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
                clientsClaim: true,
                skipWaiting: true,
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
    preview: {
        port: 4173,
        strictPort: true,
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        target: ['es2022', 'safari16', 'firefox115', 'chrome111'],
    },
});
