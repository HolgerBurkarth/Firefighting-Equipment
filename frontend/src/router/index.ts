import {
    createRouter,
    createWebHistory,
    type RouteLocationNormalized,
    type RouteLocationRaw,
    type Router,
    type RouterHistory,
    type RouteRecordRaw,
} from 'vue-router';
import { setUnauthorizedHandler } from '../api/client';
import type { AuthState, UserRole } from '../api/auth';
import { landingRoute } from '../auth/redirect';
import { ensureSession, resetSession, setLoginNotice } from '../auth/session';
import type { AppBase } from '../runtime/appBase';

declare module 'vue-router' {
    interface RouteMeta {
        /** Ohne Anmeldung erreichbar (kein auth/me). */
        public?: boolean;
        /** Weiche „/“: leitet je nach Sitzung weiter. */
        landing?: boolean;
        /** Rollen, die die Seite öffnen dürfen (nicht öffentliche Seiten). */
        roles?: readonly UserRole[];
        /**
         * Darstellung (App.vue): ohne Angabe Kopfleiste und Inhalt; `user` Handy-Seiten mit Leiste
         * unten (Suchen, Prüffristen, Profil); `focus` bildschirmfüllend ohne Kopfleiste der App
         * und ohne Leiste, die Seite zeigt ihre eigene Kopfzeile mit Zurück-Knopf.
         */
        layout?: PageLayout;
        /**
         * Ohne `layout`: trotzdem die Leiste unten, in jeder Breite (Dashboard des Admins, bis die
         * Admin-Konsole ihr eigenes Menü hat; KONZEPT.md, "Admin-Konsole").
         */
        tabBar?: boolean;
    }
}

export type PageLayout = 'user' | 'focus';

/** Handy-Seiten: User und Admin (KONZEPT.md, "User am Handy – Entscheidungen", 19). */
const DEVICE_ROLES: readonly UserRole[] = ['user', 'admin'];

/** Pfade relativ zum Basispfad; deutsche Adressen wie die Mail-Links (AppUrls in PHP). */
export function createRoutes(base: AppBase): RouteRecordRaw[] {
    const routes: RouteRecordRaw[] = [
        {
            path: '/',
            name: 'home',
            component: () => import('../pages/HomePage.vue'),
            meta: { landing: true },
        },
        {
            path: '/anmelden',
            name: 'login',
            component: () => import('../pages/LoginPage.vue'),
            meta: { public: true },
        },
        {
            path: '/passwort-vergessen',
            name: 'password-forgot',
            component: () => import('../pages/PasswordForgotPage.vue'),
            meta: { public: true },
        },
        {
            // Name wie AppUrls::PASSWORD_RESET_PAGE (Link in der Mail).
            path: '/passwort-zuruecksetzen',
            name: 'password-reset',
            component: () => import('../pages/PasswordResetPage.vue'),
            meta: { public: true },
        },
        {
            path: '/dashboard',
            name: 'dashboard',
            component: () => import('../pages/DashboardPage.vue'),
            meta: base.isService ? { roles: ['superadmin'] } : { roles: ['admin'], tabBar: true },
        },
    ];
    if (!base.isService) {
        routes.push({
            // Link im QR-Code des Einladungscodes: registrieren#code=XXXX-XXXX-XXXX.
            path: '/registrieren',
            name: 'register',
            component: () => import('../pages/RegisterPage.vue'),
            meta: { public: true },
        });
        routes.push({
            // Name wie AppUrls::VERIFY_EMAIL_PAGE (Link in der Mail).
            path: '/email-bestaetigen',
            name: 'email-verify',
            component: () => import('../pages/EmailVerifyPage.vue'),
            meta: { public: true },
        });
        routes.push({
            // Name wie AppUrls::ACCEPT_INVITATION_PAGE (Link in der Einladungsmail neuer Admins).
            path: '/einladung-annehmen',
            name: 'invitation-accept',
            component: () => import('../pages/InvitationAcceptPage.vue'),
            meta: { public: true },
        });
        routes.push({
            path: '/suchen',
            name: 'search',
            component: () => import('../pages/SearchPage.vue'),
            meta: { roles: DEVICE_ROLES, layout: 'user' },
        });
        routes.push({
            path: '/prueffristen',
            name: 'inspections',
            component: () => import('../pages/InspectionsPage.vue'),
            meta: { roles: DEVICE_ROLES, layout: 'user' },
        });
        routes.push({
            path: '/profil',
            name: 'profile',
            component: () => import('../pages/ProfilePage.vue'),
            meta: { roles: DEVICE_ROLES, layout: 'user' },
        });
        routes.push({
            // Nur positive Ganzzahlen ohne führende Null (wie die Platzhalter im Backend).
            path: '/geraete/:id([1-9]\\d{0,17})',
            name: 'device',
            component: () => import('../pages/DevicePage.vue'),
            meta: { roles: DEVICE_ROLES, layout: 'focus' },
        });
    }
    routes.push({
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('../pages/NotFoundPage.vue'),
        meta: { public: true },
    });
    return routes;
}

/**
 * Entscheidung des Wächters bei bekannter Sitzung: true = Seite öffnen, sonst Umleitung. Ohne
 * Sitzung zur Anmeldung (mit Rückkehradresse), mit falscher Rolle zur Startseite der Rolle.
 */
export function guardDecision(
    to: RouteLocationNormalized,
    state: AuthState | null,
): true | RouteLocationRaw {
    if (to.meta.landing === true) {
        return state === null ? { name: 'login' } : landingRoute(state.user.role);
    }
    if (state === null) {
        return { name: 'login', query: { weiter: to.fullPath } };
    }
    return to.meta.roles?.includes(state.user.role) === true ? true : landingRoute(state.user.role);
}

export function createAppRouter(base: AppBase, history?: RouterHistory): Router {
    const router = createRouter({
        history: history ?? createWebHistory(base.path),
        routes: createRoutes(base),
    });

    router.beforeEach(async (to) => {
        if (to.meta.public === true) {
            return true;
        }
        return guardDecision(to, await ensureSession());
    });

    // 401 einer geschützten Anfrage: Sitzung abgelaufen → Anmeldung, danach zurück.
    setUnauthorizedHandler(() => {
        const from = router.currentRoute.value;
        resetSession();
        if (from.meta.public === true) {
            return;
        }
        setLoginNotice('session_expired');
        void router.push({ name: 'login', query: { weiter: from.fullPath } });
    });

    return router;
}
