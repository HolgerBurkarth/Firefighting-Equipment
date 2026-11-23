/** Symbole des Menüs (src/components/AdminMenuIcon.vue). */
export type AdminMenuIconName =
    | 'dashboard'
    | 'search'
    | 'devices'
    | 'location'
    | 'tag'
    | 'account'
    | 'list'
    | 'wrench'
    | 'loan'
    | 'calendar'
    | 'profile'
    | 'settings'
    | 'logout'
    | 'city'
    | 'shield';

/** Zähler an einem Eintrag (aus dem Dashboard-Stand, src/data/adminOverview.ts). */
export type AdminMenuBadge = 'pendingUsers' | 'openNotices' | 'overdueLoans';

export interface AdminMenuEntry {
    /** Name der Route (src/router/index.ts). */
    readonly route: string;
    readonly label: string;
    readonly icon: AdminMenuIconName;
    readonly badge?: AdminMenuBadge;
    /** Weitere Seiten, auf denen der Eintrag hervorgehoben ist (z. B. das Gerätedetail). */
    readonly sections?: readonly string[];
}

/**
 * Menü der Admin-Konsole (KONZEPT.md, "Admin-Konsole – Entscheidungen", 34), in dieser
 * Reihenfolge: Dashboard, Suchen, Termine, Ausrüstung, Standorte, Ausrüstungstypen, Wartung,
 * Verleih, Benutzer, Protokoll, Profil. Suchen, Termine und Profil sind die Handy-Seiten
 * (`HANDY_MENU_ROUTES`, seit 23.11.2026 auch im Menü, Wunsch Holger). Jeder Eintrag kommt mit seiner
 * Seite hinzu; unten stehen „Einstellungen“ (ADMIN_SETTINGS) und „Abmelden“.
 */
export const ADMIN_MENU: readonly AdminMenuEntry[] = [
    { route: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
    { route: 'search', label: 'Suchen', icon: 'search' },
    { route: 'inspections', label: 'Termine', icon: 'calendar' },
    {
        route: 'devices',
        label: 'Ausrüstung',
        icon: 'devices',
        sections: ['device-details', 'device-new', 'device-import'],
    },
    { route: 'locations', label: 'Standorte', icon: 'location' },
    { route: 'device-types', label: 'Ausrüstungstypen', icon: 'tag' },
    { route: 'maintenance', label: 'Wartung', icon: 'wrench' },
    { route: 'loans', label: 'Verleih', icon: 'loan', badge: 'overdueLoans' },
    { route: 'users', label: 'Benutzer', icon: 'account', badge: 'pendingUsers' },
    {
        route: 'notices',
        label: 'Protokoll',
        icon: 'list',
        badge: 'openNotices',
        sections: ['notice'],
    },
    { route: 'profile', label: 'Profil', icon: 'profile' },
];

/**
 * Handy-Seiten im Menü (Layout `user`): Sie allein ergeben keine Konsole (`consoleRoute`, F8), jedes
 * Konto einer Stadt hat sie je nach Rechten in der Leiste unten.
 */
export const HANDY_MENU_ROUTES: readonly string[] = ['search', 'inspections', 'profile'];

/** Unten im Menü über dem Namen des Kontos: Einstellungen (Anzeigemodus, Erscheinungsbild …). */
export const ADMIN_SETTINGS: AdminMenuEntry = {
    route: 'settings',
    label: 'Einstellungen',
    icon: 'settings',
};

/**
 * Menü des Super-Admins unter `service` (KONZEPT.md, "Super-Admin – Entscheidungen", 40):
 * Dashboard, Städte, Super-Admins (jeder Eintrag kommt mit seiner Seite); unten ebenfalls
 * „Einstellungen“ (ADMIN_SETTINGS, eigene Seite unter service) und „Abmelden“.
 */
export const SERVICE_MENU: readonly AdminMenuEntry[] = [
    { route: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
    { route: 'tenants', label: 'Städte', icon: 'city', sections: ['tenant', 'role', 'role-new'] },
    { route: 'super-admins', label: 'Super-Admins', icon: 'shield' },
];
