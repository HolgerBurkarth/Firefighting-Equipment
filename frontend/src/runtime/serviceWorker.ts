import type { AppBase } from './appBase';

/**
 * Registriert den Service Worker des Builds (sw.js) für den Bereich. Er liegt unter dem
 * Basispfad, sein Scope ist damit genau /firefighting/<bereich>/. Eine neue Version wartet, bis
 * die App sie aktiviert (runtime/appUpdate.ts).
 */
export async function registerServiceWorker(
    base: AppBase,
): Promise<ServiceWorkerRegistration | null> {
    if (!('serviceWorker' in navigator)) {
        return null;
    }
    try {
        // Gesperrte Service Worker (z. B. Playwright `serviceWorkers: 'block'`) liefern undefined.
        const registration = (await navigator.serviceWorker.register(
            new URL('sw.js', document.baseURI).href,
            { scope: base.path },
        )) as ServiceWorkerRegistration | undefined;
        return registration ?? null;
    } catch {
        // Ohne Service Worker läuft die App weiter, nur nicht offline.
        return null;
    }
}
