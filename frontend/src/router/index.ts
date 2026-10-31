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
         * und ohne Leiste, die Seite zeigt ihre eigene Kopfzeile mit Zurück-Knopf; `admin` Seiten
         * der Admin-Konsole bzw. des Super-Admins mit Menü (src/components/AdminMenu.vue).
         */
        layout?: PageLayout;
    }
}

export type PageLayout = 'user' | 'focus' | 'admin';

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
            // Unter einer Stadt die Startseite der Admin-Konsole, unter service die des Super-Admins.
            component: base.isService
                ? () => import('../pages/ServiceDashboardPage.vue')
                : () => import('../pages/AdminDashboardPage.vue'),
            meta: base.isService
                ? { roles: ['superadmin'], layout: 'admin' }
                : { roles: ['admin'], layout: 'admin' },
        },
    ];
    if (base.isService) {
        // Bereich des Super-Admins (KONZEPT.md, "Super-Admin – Entscheidungen"); unter einer Stadt
        // gibt es diese Seiten nicht.
        routes.push({
            path: '/staedte',
            name: 'tenants',
            component: () => import('../pages/TenantsPage.vue'),
            meta: { roles: ['superadmin'], layout: 'admin' },
        });
        routes.push({
            // Stadtdetail (Entscheidung 44); ID wie die Platzhalter im Backend.
            path: '/staedte/:id([1-9]\\d{0,17})',
            name: 'tenant',
            component: () => import('../pages/TenantDetailPage.vue'),
            meta: { roles: ['superadmin'], layout: 'admin' },
        });
        routes.push({
            path: '/super-admins',
            name: 'super-admins',
            component: () => import('../pages/SuperAdminsPage.vue'),
            meta: { roles: ['superadmin'], layout: 'admin' },
        });
        routes.push({
            path: '/einstellungen',
            name: 'settings',
            component: () => import('../pages/ServiceSettingsPage.vue'),
            meta: { roles: ['superadmin'], layout: 'admin' },
        });
    }
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
            // Filter in der Adresse (src/data/deviceList.ts), z. B. geraete?status=defective.
            path: '/geraete',
            name: 'devices',
            component: () => import('../pages/DevicesPage.vue'),
            meta: { roles: ['admin'], layout: 'admin' },
        });
        routes.push({
            // Neues Gerät mit „Speichern und nächstes“ (KONZEPT.md, Entscheidung 32).
            path: '/geraete/neu',
            name: 'device-new',
            component: () => import('../pages/DeviceNewPage.vue'),
            meta: { roles: ['admin'], layout: 'admin' },
        });
        routes.push({
            // CSV-Import mit Vorlage und Vorschau (KONZEPT.md, Entscheidung 32).
            path: '/geraete/import',
            name: 'device-import',
            component: () => import('../pages/DeviceImportPage.vue'),
            meta: { roles: ['admin'], layout: 'admin' },
        });
        routes.push({
            path: '/standorte',
            name: 'locations',
            component: () => import('../pages/LocationsPage.vue'),
            meta: { roles: ['admin'], layout: 'admin' },
        });
        routes.push({
            path: '/geraetetypen',
            name: 'device-types',
            component: () => import('../pages/DeviceTypesPage.vue'),
            meta: { roles: ['admin'], layout: 'admin' },
        });
        routes.push({
            // Nur positive Ganzzahlen ohne führende Null (wie die Platzhalter im Backend).
            path: '/geraete/:id([1-9]\\d{0,17})',
            name: 'device',
            component: () => import('../pages/DevicePage.vue'),
            meta: { roles: DEVICE_ROLES, layout: 'focus' },
        });
        routes.push({
            // Gerätedetail für Admins (KONZEPT.md, "Admin-Konsole – Entscheidungen", 33).
            path: '/geraete/:id([1-9]\\d{0,17})/details',
            name: 'device-details',
            component: () => import('../pages/DeviceDetailPage.vue'),
            meta: { roles: ['admin'], layout: 'admin' },
        });
        routes.push({
            // Benutzerverwaltung (Ziel der Mail „Neue Registrierung“, AppUrls::users).
            path: '/benutzer',
            name: 'users',
            component: () => import('../pages/UsersPage.vue'),
            meta: { roles: ['admin'], layout: 'admin' },
        });
        routes.push({
            // Protokoll: Hinweise zu Offline-Buchungen und Export des Verlaufs (KONZEPT.md,
            // "Hinweise für Admins"); Reiter und Filter in der Adresse.
            path: '/protokoll',
            name: 'notices',
            component: () => import('../pages/NoticesPage.vue'),
            meta: { roles: ['admin'], layout: 'admin' },
        });
        routes.push({
            // Einstellungen: Anzeigemodus, Erscheinungsbild der Stadt, Benachrichtigung „Log“,
            // Passwort und angemeldete Geräte (KONZEPT.md, "Bereiche des Admins").
            path: '/einstellungen',
            name: 'settings',
            component: () => import('../pages/SettingsPage.vue'),
            meta: { roles: ['admin'], layout: 'admin' },
        });
        routes.push({
            // Ein Hinweis (Ziel der Hinweis-Mails, AppUrls::notice).
            path: '/protokoll/:id([1-9]\\d{0,17})',
            name: 'notice',
            component: () => import('../pages/NoticePage.vue'),
            meta: { roles: ['admin'], layout: 'admin' },
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
