import type { RouteLocationNormalizedLoaded, Router } from 'vue-router';

/**
 * Wert aus dem Fragment einer Adresse, z. B. "token" aus "#token=abc". Tokens und Einladungscodes
 * stehen in Links hinter "#", damit sie nie an den Server, in Protokolle oder in den Referer
 * gelangen.
 */
export function readFragmentParam(hash: string, name: string): string | null {
    const value = new URLSearchParams(hash.replace(/^#/, '')).get(name);
    return value === null || value === '' ? null : value;
}

/**
 * Liest den Wert aus dem Fragment der aktuellen Seite und entfernt das Fragment danach aus der
 * Adresszeile (der Wert bleibt nur im Speicher der Seite; neu laden zeigt ihn nicht mehr).
 */
export function takeFragmentParam(
    router: Router,
    route: RouteLocationNormalizedLoaded,
    name: string,
): string | null {
    const value = readFragmentParam(route.hash, name);
    if (route.hash !== '') {
        void router.replace({ path: route.path, query: route.query, hash: '' });
    }
    return value;
}
