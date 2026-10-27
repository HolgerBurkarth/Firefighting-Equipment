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
    }
}

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
            path: '/dashboard',
            name: 'dashboard',
            component: () => import('../pages/DashboardPage.vue'),
            meta: { roles: base.isService ? ['superadmin'] : ['admin'] },
        },
    ];
    if (!base.isService) {
        routes.push({
            path: '/suchen',
            name: 'search',
            component: () => import('../pages/SearchPage.vue'),
            meta: { roles: ['user'] },
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
