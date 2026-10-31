<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { userLabel } from './api/auth';
import { session } from './auth/session';
import AdminMenu from './components/AdminMenu.vue';
import AppNotices from './components/AppNotices.vue';
import UserTabBar from './components/UserTabBar.vue';
import type { AppBase } from './runtime/appBase';
import type { AppUpdater } from './runtime/appUpdate';
import { loadTenant, tenantState } from './runtime/tenantState';

const props = defineProps<{ base: AppBase; updater: AppUpdater }>();

const route = useRoute();
/** Darstellung der Seite (RouteMeta.layout in src/router/index.ts). */
const layout = computed(() => route.meta.layout ?? 'standard');
/** Handy-Seiten: „Hallo <Anzeigename>“ statt „Geräteverwaltung“ (Mockup UserStart). */
const subtitle = computed(() =>
    layout.value === 'user' && session.value !== null
        ? `Hallo ${userLabel(session.value.user)}`
        : 'Geräteverwaltung',
);

onMounted(() => {
    void loadTenant(props.base);
});
</script>

<template>
    <!-- Admin-Konsole und Super-Admin: Menü links (am Handy Kopfleiste mit ☰), Inhalt rechts. -->
    <div v-if="layout === 'admin'" class="admin-shell">
        <AdminMenu :service="base.isService" />
        <div class="admin-column">
            <AppNotices :updater="updater" />
            <main class="app-main app-main-admin">
                <p
                    v-if="updater.loadingPage.value"
                    class="hint"
                    role="status"
                    data-testid="update-loading"
                >
                    Seite wird geladen …
                </p>
                <RouterView />
            </main>
        </div>
    </div>

    <template v-else>
        <!-- Bildschirmfüllende Seiten (focus) zeigen ihre eigene Kopfzeile mit Zurück-Knopf. -->
        <header v-if="layout !== 'focus'" class="app-header">
            <div class="app-header-icon">
                <svg
                    width="22"
                    height="22"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z" />
                </svg>
            </div>
            <div class="app-header-text">
                <span class="app-header-title" data-testid="app-title">
                    {{ base.isService ? 'Super-Admin' : (tenantState.tenant?.name ?? 'Feuerwehr') }}
                </span>
                <span class="app-header-subtitle" data-testid="app-subtitle">{{ subtitle }}</span>
            </div>
        </header>

        <AppNotices :updater="updater" />

        <main class="app-main" :class="`app-main-${layout}`">
            <!-- Start mit veralteter Version: Die erste Seite wartet auf die neue. -->
            <p
                v-if="updater.loadingPage.value"
                class="hint"
                role="status"
                data-testid="update-loading"
            >
                Seite wird geladen …
            </p>
            <RouterView />
        </main>
        <UserTabBar v-if="layout === 'user'" />
    </template>
</template>

<style scoped>
.app-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px 16px 16px;
    background: var(--accent);
    color: var(--on-accent);
}

.app-header-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: var(--radius-md);
    background: var(--on-accent-soft);
}

.app-header-text {
    display: flex;
    flex-direction: column;
}

.app-header-title {
    font-size: 18px;
    font-weight: var(--font-weight-bold);
}

.app-header-subtitle {
    font-size: var(--font-size-sm);
    opacity: 0.85;
}

.hint {
    margin: 0;
    color: var(--muted);
}

.app-main {
    flex: 1 0 auto;
    display: flex;
    flex-direction: column;
    gap: 20px;
    max-width: 980px;
    padding: 16px;
}

/* Handy-Seiten: eine Spalte, am PC mittig; die Leiste unten bleibt am unteren Rand. */
.app-main-user,
.app-main-focus {
    flex: 1 0 auto;
    width: 100%;
    max-width: 600px;
    margin: 0 auto;
}

.app-main-focus {
    gap: 0;
    padding: 0;
}

/* Admin-Konsole: am Handy untereinander, ab 768 px Menü links und Inhalt rechts. */
.admin-shell {
    display: flex;
    flex: 1 0 auto;
    flex-direction: column;
}

.admin-column {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-width: 0;
}

.app-main-admin {
    gap: 24px;
    width: 100%;
    max-width: 1280px;
}

@media (min-width: 768px) {
    .app-main-standard,
    .app-main-admin {
        padding: 28px 32px;
    }

    .admin-shell {
        flex-direction: row;
        align-items: flex-start;
    }
}
</style>
