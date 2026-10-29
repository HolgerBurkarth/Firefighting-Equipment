<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { userLabel } from './api/auth';
import { errorMessage } from './auth/errorMessages';
import { session, sessionCheckError } from './auth/session';
import ConfirmDialog from './components/ConfirmDialog.vue';
import UserTabBar from './components/UserTabBar.vue';
import type { AppBase } from './runtime/appBase';
import { updateNotice, type AppUpdater } from './runtime/appUpdate';
import { loadTenant, tenantState } from './runtime/tenantState';

const props = defineProps<{ base: AppBase; updater: AppUpdater }>();

const notice = computed(() => updateNotice(props.updater));

const route = useRoute();
/** Darstellung der Seite (RouteMeta.layout in src/router/index.ts). */
const layout = computed(() => route.meta.layout ?? 'standard');
/** Handy-Seiten: „Hallo <Anzeigename>“ statt „Geräteverwaltung“ (Mockup UserStart). */
const subtitle = computed(() =>
    layout.value === 'user' && session.value !== null
        ? `Hallo ${userLabel(session.value.user)}`
        : 'Geräteverwaltung',
);

const confirmOpen = ref(false);

/** Mit ungespeicherten Eingaben erst nachfragen (sie gingen beim Neuladen verloren). */
function updateNow(): void {
    if (notice.value === 'unsaved') {
        confirmOpen.value = true;
        return;
    }
    applyUpdate();
}

function applyUpdate(): void {
    void props.updater.apply(window.location.href);
}

/** Neue Version ließ sich beim Start nicht laden: vom Server neu laden, samt #token bzw. #code. */
function reloadFromServer(): void {
    void props.updater.reloadFromServer(window.location.href);
}

onMounted(() => {
    void loadTenant(props.base);
});
</script>

<template>
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

    <p v-if="tenantState.error !== null" class="app-error" role="alert">
        {{ errorMessage(tenantState.error) }}
        <small>({{ tenantState.error.code }})</small>
    </p>

    <!-- Sitzungsprüfung gestört (keine Verbindung, 5xx): nie zur Anmeldung, sondern erneut versuchen. -->
    <p
        v-if="sessionCheckError !== null"
        class="app-error app-offline"
        role="status"
        data-testid="connection-notice"
    >
        Keine Verbindung zum Server, neuer Versuch läuft automatisch.
        <small>({{ sessionCheckError.code }})</small>
    </p>

    <div
        v-if="updater.reloadNeeded.value"
        class="app-update"
        role="status"
        data-testid="update-reload-needed"
    >
        <span>Die neue Version konnte nicht geladen werden.</span>
        <button class="btn app-update-button" type="button" @click="reloadFromServer">
            Neu laden
        </button>
    </div>

    <p
        v-else-if="updater.switching.value"
        class="app-update"
        role="status"
        data-testid="update-switching"
    >
        Neue Version wird geladen …
    </p>

    <!-- Neue Version, aber gerade nicht automatisch: Formular angefangen oder kurz zuvor neu geladen. -->
    <div v-else-if="notice !== null" class="app-update" role="status" data-testid="update-notice">
        <span>
            {{
                notice === 'unsaved'
                    ? 'Neue Version verfügbar – nach dem Speichern wird aktualisiert.'
                    : 'Neue Version verfügbar.'
            }}
        </span>
        <button class="btn app-update-button" type="button" @click="updateNow">
            Jetzt aktualisieren
        </button>
    </div>
    <ConfirmDialog
        v-model:open="confirmOpen"
        title="Jetzt aktualisieren?"
        description="Ihre Eingaben auf dieser Seite gehen verloren. Trotzdem aktualisieren?"
        confirm-label="Aktualisieren"
        @confirm="applyUpdate"
    />

    <main class="app-main" :class="`app-main-${layout}`">
        <!-- Start mit veralteter Version: Die erste Seite wartet auf die neue. -->
        <p v-if="updater.loadingPage.value" class="hint" role="status" data-testid="update-loading">
            Seite wird geladen …
        </p>
        <RouterView />
    </main>
    <UserTabBar
        v-if="layout === 'user' || route.meta.mobileTabBar === true"
        :mobile-only="layout !== 'user'"
    />
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

.app-error {
    margin: 0;
    padding: 10px 16px;
    background: var(--status-defective-bg);
    color: var(--status-defective-fg);
    font-weight: var(--font-weight-semibold);
}

.app-offline {
    background: var(--offline-bg);
    color: var(--offline-text);
}

.app-update {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 16px;
    padding: 8px 16px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    font-weight: var(--font-weight-semibold);
}

.app-update-button {
    flex: none;
}

.app-error small {
    font-weight: var(--font-weight-normal);
    opacity: 0.8;
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

@media (min-width: 768px) {
    .app-main-standard {
        padding: 28px 32px;
    }
}
</style>
