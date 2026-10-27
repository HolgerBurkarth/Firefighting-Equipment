/** Bereich des Super-Admins (wie AppPath::SERVICE_SCOPE in PHP). */
export const SERVICE_AREA = 'service';

const AREA_PATTERN = /^[a-z0-9-]+$/;

export interface AppBase {
    /** Basispfad mit Slash am Ende, z. B. "/firefighting/immendingen/". */
    readonly path: string;
    /** Kürzel der Stadt oder "service". */
    readonly area: string;
    readonly isService: boolean;
}

/**
 * Liest Basispfad und Bereich aus der Basis-Adresse des Dokuments (<base href>, vom Server je
 * Anfrage eingesetzt). Der Bereich ist das letzte Segment; das Präfix kennt nur der Server.
 */
export function parseAppBase(baseUri: string): AppBase | null {
    const path = new URL(baseUri).pathname;
    if (!path.endsWith('/')) {
        return null;
    }
    const segments = path.slice(1, -1).split('/');
    const area = segments.at(-1) ?? '';
    if (segments.length < 2 || !AREA_PATTERN.test(area)) {
        return null;
    }
    return { path, area, isService: area === SERVICE_AREA };
}

export function currentAppBase(): AppBase {
    const base = parseAppBase(document.baseURI);
    if (base === null) {
        throw new Error(`Ungültiger Basispfad: ${document.baseURI}`);
    }
    return base;
}

/** Absolute Adresse eines API-Endpunkts im aktuellen Bereich, z. B. apiUrl('tenant'). */
export function apiUrl(path: string, baseUri: string = document.baseURI): string {
    return new URL(`api/v1/${path}`, baseUri).href;
}
