import type { Plugin } from 'vite';
import type { VitePWAOptions } from 'vite-plugin-pwa';

/** Filter der Workbox-Liste (Typ aus vite-plugin-pwa, workbox-build ist nur dessen Abhängigkeit). */
type ManifestTransform = NonNullable<
    NonNullable<VitePWAOptions['workbox']>['manifestTransforms']
>[number];

/**
 * Vorab-Laden des Service Workers verkleinern (T15, Runde 7): Workbox legt jede Datei einzeln per
 * cache.put ab, in Firefox unter Windows rund 50 ms je Datei; mit allen 121 Dateien dauerte die
 * Installation 8–13 s (Updates beim Seitenwechsel warten höchstens 5 s). Vorab geladen werden nur
 * noch die Seiten, die offline gehen müssen (KONZEPT.md, "Offline-Modus – Entscheidungen"), und die
 * öffentlichen Ziele der Mail-Links, mit allem, was sie statisch oder per import() nachladen
 * (Scanner samt Lesemodul), dazu der Einstieg der App, index.html und die Schriften. Die übrigen
 * Seiten (Admin-Konsole …) lädt die App bei Bedarf; der Service Worker legt sie dabei ab
 * (RUNTIME_ASSET_CACHE). Fehlt einer alten Version nach einem Deploy eine solche Seite, lädt die App
 * die neue (isChunkLoadError in src/runtime/appUpdate.ts).
 */

/** Seiten (src/pages/<Name>.vue), die vorab geladen werden; per Test gleich den Routen. */
export const PRECACHED_PAGES: readonly string[] = [
    // Weiche der Startseite, Anmeldung (abgelaufene Sitzung) und „Seite nicht gefunden“.
    'HomePage',
    'LoginPage',
    'NotFoundPage',
    // Handy-Seiten: Suchen (mit Scanner), Umbuchen, Termine, Profil.
    'SearchPage',
    'DevicePage',
    'InspectionsPage',
    'ProfilePage',
    // Startseite der Konsole: Admins landen offline dort (landingRoute), von dort zu „Suchen“.
    'AdminDashboardPage',
    // Öffentliche Ziele der Mail-Links (AppUrls): mit der Version im Service Worker sofort da,
    // auch wenn seit dem letzten Besuch ein Deploy war (Schnellstart, Plan Phase 1, 1.7d).
    'PasswordResetPage',
    'EmailVerifyPage',
    'InvitationAcceptPage',
    'RegisterPage',
];

/** Immer vorab: die App-Seite und die Schriften (in den CSS-Dateien per url() verwiesen). */
const ALWAYS = /^(?:index\.html|assets\/[^/]+\.woff2)$/;

/** Cache für die übrigen Dateien aus assets/ (Namen mit Hash, also unveränderlich). */
export const RUNTIME_ASSET_CACHE = 'ff-assets';

/** Höchstens so viele Dateien im RUNTIME_ASSET_CACHE (ältere fallen weg, mehrere Versionen). */
export const RUNTIME_ASSET_MAX_ENTRIES = 200;

/** Teil eines Bundles, wie ihn Rollup/Rolldown in generateBundle liefert (nur, was hier zählt). */
export interface BundleChunk {
    type: 'chunk';
    fileName: string;
    isEntry: boolean;
    facadeModuleId: string | null;
    imports: readonly string[];
    dynamicImports: readonly string[];
    viteMetadata?: {
        importedCss: ReadonlySet<string>;
        importedAssets: ReadonlySet<string>;
    };
}

export interface BundleAsset {
    type: 'asset';
    fileName: string;
}

/**
 * Dateien, die vorab geladen werden: der Einstieg mit seinen statischen Importen (nicht seinen
 * import(): darüber erreicht der Router jede Seite) und je Seite aus PRECACHED_PAGES alles, was sie
 * statisch oder per import() erreicht, jeweils mit CSS und Dateien (z. B. das WASM des Scanners).
 * Fehlt eine Seite im Bundle, bricht der Build ab (sonst ginge sie offline still verloren).
 */
export function offlineFiles(
    bundle: Readonly<Record<string, BundleChunk | BundleAsset>>,
    pages: readonly string[] = PRECACHED_PAGES,
): Set<string> {
    const chunks = new Map<string, BundleChunk>();
    for (const item of Object.values(bundle)) {
        if (item.type === 'chunk') {
            chunks.set(item.fileName, item);
        }
    }
    const files = new Set<string>();
    // Teil → schon mit import() besucht; ein Teil des Einstiegs, den eine Seite erreicht, wird mit
    // import() noch einmal besucht.
    const seen = new Map<string, boolean>();
    const visit = (fileName: string, followDynamic: boolean): void => {
        const chunk = chunks.get(fileName);
        const before = seen.get(fileName);
        if (chunk === undefined || before === true || (before === false && !followDynamic)) {
            return;
        }
        seen.set(fileName, followDynamic);
        files.add(fileName);
        for (const css of chunk.viteMetadata?.importedCss ?? []) {
            files.add(css);
        }
        for (const asset of chunk.viteMetadata?.importedAssets ?? []) {
            files.add(asset);
        }
        for (const next of chunk.imports) {
            visit(next, followDynamic);
        }
        // Die Seiten importieren Teile des Einstiegs statisch; dessen import() bleiben außen vor.
        if (followDynamic && !chunk.isEntry) {
            for (const next of chunk.dynamicImports) {
                visit(next, true);
            }
        }
    };

    const entries = [...chunks.values()].filter((chunk) => chunk.isEntry);
    if (entries.length === 0) {
        throw new Error('Vorab-Laden: kein Einstieg im Bundle.');
    }
    for (const entry of entries) {
        visit(entry.fileName, false);
    }
    for (const page of pages) {
        const chunk = [...chunks.values()].find((c) =>
            (c.facadeModuleId ?? '').replaceAll('\\', '/').endsWith(`/src/pages/${page}.vue`),
        );
        if (chunk === undefined) {
            throw new Error(`Vorab-Laden: Seite ${page} nicht im Bundle.`);
        }
        visit(chunk.fileName, true);
    }
    return files;
}

/**
 * Vite-Plugin: bestimmt beim Schreiben des Bundles die Dateien für das Vorab-Laden. Die
 * Workbox-Konfiguration filtert damit ihre Liste (`manifestTransforms: [plugin.manifestTransform]`);
 * vite-plugin-pwa erzeugt sw.js erst nach dem Bundle.
 */
export function precacheFilter(): Plugin & { manifestTransform: ManifestTransform } {
    let files: Set<string> | null = null;
    return {
        name: 'ff-precache-filter',
        apply: 'build',
        generateBundle(_options, bundle) {
            files = offlineFiles(bundle);
        },
        // Nicht „transform“: so heißt ein Hook von Vite.
        manifestTransform: (entries) => {
            if (files === null) {
                throw new Error('Vorab-Laden: Liste der Dateien fehlt (Plugin nicht gelaufen).');
            }
            const keep = files;
            const manifest = entries.filter(
                (entry) => ALWAYS.test(entry.url) || keep.has(entry.url),
            );
            return { manifest, warnings: [] };
        },
    };
}
