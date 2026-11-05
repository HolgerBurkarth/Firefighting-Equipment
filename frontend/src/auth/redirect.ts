import type { RouteLocationRaw, RouteMeta, Router } from 'vue-router';
import type { AuthState } from '../api/auth';
import { permissionsOf } from '../api/permissions';
import { ADMIN_MENU, ADMIN_SETTINGS } from '../router/adminMenu';

/** Darf das Konto die Seite öffnen (Rolle und unter einer Stadt die Rechte, `meta.rights`)? */
export function canOpen(meta: RouteMeta, state: AuthState): boolean {
    if (meta.roles?.includes(state.user.role) !== true) {
        return false;
    }
    const permissions = permissionsOf(state);
    return meta.rights === undefined || (permissions !== null && meta.rights(permissions));
}

/**
 * Gibt es die Seite `name` im Bereich und darf das Konto sie öffnen? `meta` aus der Route selbst,
 * nicht über `router.resolve` (das wirft bei Seiten mit Platzhalter wie `geraete/:id/details`).
 */
export function canOpenRoute(router: Router, name: string, state: AuthState): boolean {
    const record = router.getRoutes().find((route) => route.name === name);
    return record !== undefined && canOpen(record.meta, state);
}

/**
 * Erste Seite der Admin-Konsole, die das Konto öffnen darf: das Dashboard, sonst der erste erlaubte
 * Eintrag des Menüs (ohne „Suchen“, eine Handy-Seite), sonst „Einstellungen“; null ohne Konsole.
 * Ziel von „Dashboard“ in der Handy-Leiste (Plan Phase 1, F8).
 */
export function consoleRoute(router: Router, state: AuthState): RouteLocationRaw | null {
    const names = [...ADMIN_MENU, ADMIN_SETTINGS]
        .map((entry) => entry.route)
        .filter((name) => name !== 'search');
    const name = names.find((candidate) => canOpenRoute(router, candidate, state));
    return name === undefined ? null : { name };
}

/**
 * Startseite: mit einer Konsole deren erste erlaubte Seite (Admin und Super-Admin das Dashboard),
 * sonst die Handy-Seiten „Suchen“, „Prüffristen“, zuletzt „Profil“ (jedes Konto einer Stadt).
 */
export function landingRoute(router: Router, state: AuthState): RouteLocationRaw {
    if (state.user.role === 'superadmin') {
        return { name: 'dashboard' };
    }
    const start = consoleRoute(router, state);
    if (start !== null) {
        return start;
    }
    const name = ['search', 'inspections'].find((candidate) =>
        canOpenRoute(router, candidate, state),
    );
    return { name: name ?? 'profile' };
}

/**
 * Rückkehradresse aus ?weiter= nur, wenn sie ein Pfad innerhalb der App ist (kein Schema, kein
 * „//“, kein Backslash, keine Steuerzeichen); sonst null. Schützt vor Umleitungen auf fremde
 * Seiten.
 */
export function safeReturnPath(value: unknown): string | null {
    if (typeof value !== 'string' || value.length > 2000) {
        return null;
    }
    if (!value.startsWith('/') || value.startsWith('//') || /[\\\p{Cc}]/u.test(value)) {
        return null;
    }
    return value;
}

/**
 * Ziel nach der Anmeldung: die Rückkehradresse, wenn es sie gibt und das Konto sie öffnen darf
 * (keine öffentliche Seite wie anmelden), sonst die Startseite der Rolle.
 */
export function afterLogin(router: Router, state: AuthState, returnTo: unknown): RouteLocationRaw {
    const path = safeReturnPath(returnTo);
    if (path !== null) {
        const target = router.resolve(path);
        if (target.matched.length > 0 && canOpen(target.meta, state)) {
            return target.fullPath;
        }
    }
    return landingRoute(router, state);
}
