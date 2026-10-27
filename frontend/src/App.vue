<script setup lang="ts">
import { onMounted } from 'vue';
import { errorMessage } from './auth/errorMessages';
import { sessionCheckError } from './auth/session';
import type { AppBase } from './runtime/appBase';
import { loadTenant, tenantState } from './runtime/tenantState';

const props = defineProps<{ base: AppBase }>();

onMounted(() => {
    void loadTenant(props.base);
});
</script>

<template>
    <header class="app-header">
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
            <span class="app-header-subtitle">Geräteverwaltung</span>
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

    <main class="app-main">
        <RouterView />
    </main>
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

.app-error small {
    font-weight: var(--font-weight-normal);
    opacity: 0.8;
}

.app-main {
    display: flex;
    flex-direction: column;
    gap: 20px;
    max-width: 980px;
    padding: 16px;
}

@media (min-width: 768px) {
    .app-main {
        padding: 28px 32px;
    }
}
</style>
