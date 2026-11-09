import { allows, can, type Permissions } from '../api/permissions';

/**
 * Welche Rechte eine Seite unter einer Stadt braucht (Plan Phase 1, Schritt 1.7b); dieselben wie die
 * Abfragen, die sie stellt (Tabelle „Bestehende Route → benötigtes Recht“ im Plan). Ohne Recht
 * leitet der Wächter still zur Startseite der Rolle.
 */
export type PageRights = (permissions: Permissions) => boolean;

/** Handy-Seiten: Suchen und Termine. Jeder andere Bereich ergibt eine Konsole (rolePreview, F8). */
const HANDY_AREAS: readonly string[] = ['search', 'due'];

/** Hat die Rolle eine Seite der Admin-Konsole (dann auch „Dashboard“ in der Handy-Leiste, F8)? */
export function hasConsole(permissions: Permissions): boolean {
    return Object.keys(permissions.areas).some((area) => !HANDY_AREAS.includes(area));
}

const devicesRead: PageRights = (p) => allows(p, 'devices');
const logRead: PageRights = (p) => allows(p, 'log');

export const PAGE_RIGHTS = {
    search: (p) => allows(p, 'search'),
    inspections: (p) => allows(p, 'due'),
    // Umbuchen-Ansicht (auch aus der Konsole über „Umbuchen / Status ändern“): GET devices/{id}.
    device: (p) => allows(p, 'search') || allows(p, 'devices'),
    dashboard: (p) => allows(p, 'dashboard'),
    devices: devicesRead,
    'device-details': devicesRead,
    'device-new': (p) => can(p, 'device.create'),
    'device-import': (p) => can(p, 'csv.import'),
    locations: (p) => allows(p, 'locations'),
    'device-types': (p) => allows(p, 'device_types'),
    users: (p) => allows(p, 'users'),
    // Nur in Städten mit Sammelstelle oder Werkstatt sendet der Server den Bereich (Phase 2, P2.4).
    maintenance: (p) => allows(p, 'maintenance'),
    notices: logRead,
    notice: logRead,
    // Anzeigemodus, Passwort und Geräte für jede Konsole; Farbe und Logo nur mit `settings`.
    settings: hasConsole,
} satisfies Readonly<Record<string, PageRights>>;
