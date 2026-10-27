import type { AppBase } from './appBase';

/**
 * Registriert den Service Worker des Builds (sw.js) für den Bereich. Er liegt unter dem
 * Basispfad, sein Scope ist damit genau /firefighting/<bereich>/.
 */
export async function registerServiceWorker(base: AppBase): Promise<void> {
    if (!('serviceWorker' in navigator)) {
        return;
    }
    try {
        await navigator.serviceWorker.register(new URL('sw.js', document.baseURI).href, {
            scope: base.path,
        });
    } catch {
        // Ohne Service Worker läuft die App weiter, nur nicht offline.
    }
}
