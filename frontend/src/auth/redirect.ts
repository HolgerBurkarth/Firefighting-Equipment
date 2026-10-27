import type { RouteLocationRaw, Router } from 'vue-router';
import type { UserRole } from '../api/auth';

/** Startseite je Rolle: User am Handy „Suchen“, Admin und Super-Admin das Dashboard. */
export function landingRoute(role: UserRole): RouteLocationRaw {
    return { name: role === 'user' ? 'search' : 'dashboard' };
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
 * Ziel nach der Anmeldung: die Rückkehradresse, wenn es sie gibt und die Rolle sie öffnen darf
 * (keine öffentliche Seite wie anmelden), sonst die Startseite der Rolle.
 */
export function afterLogin(router: Router, role: UserRole, returnTo: unknown): RouteLocationRaw {
    const path = safeReturnPath(returnTo);
    if (path !== null) {
        const target = router.resolve(path);
        const roles = target.meta.roles;
        if (target.matched.length > 0 && roles?.includes(role) === true) {
            return target.fullPath;
        }
    }
    return landingRoute(role);
}
