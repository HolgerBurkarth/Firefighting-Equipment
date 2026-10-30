/** Symbole des Menüs (src/components/AdminMenuIcon.vue). */
export type AdminMenuIconName = 'dashboard' | 'search' | 'location' | 'tag' | 'account' | 'logout';

/** Zähler an einem Eintrag (aus dem Dashboard-Stand, src/data/adminOverview.ts). */
export type AdminMenuBadge = 'pendingUsers' | 'openNotices';

export interface AdminMenuEntry {
    /** Name der Route (src/router/index.ts). */
    readonly route: string;
    readonly label: string;
    readonly icon: AdminMenuIconName;
    readonly badge?: AdminMenuBadge;
}

/**
 * Menü der Admin-Konsole (KONZEPT.md, "Admin-Konsole – Entscheidungen", 34), in dieser
 * Reihenfolge: Dashboard, Suchen, Geräte, Standorte, Gerätetypen, Benutzer, Protokoll. Jeder
 * Eintrag kommt mit seiner Seite hinzu; unten stehen immer „Einstellungen“ (mit der Seite) und
 * „Abmelden“.
 */
export const ADMIN_MENU: readonly AdminMenuEntry[] = [
    { route: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
    { route: 'search', label: 'Suchen', icon: 'search' },
    { route: 'locations', label: 'Standorte', icon: 'location' },
    { route: 'device-types', label: 'Gerätetypen', icon: 'tag' },
];
