<script setup lang="ts">
import { computed, ref } from 'vue';
import { errorMessage } from '../auth/errorMessages';
import { sessionCheckError } from '../auth/session';
import { updateNotice, type AppUpdater } from '../runtime/appUpdate';
import { tenantState } from '../runtime/tenantState';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * Hinweise oben auf jeder Seite: Fehler der Stadt, gestörte Sitzungsprüfung, Updates der App (siehe
 * KONZEPT.md, "Updates der App"). In allen Darstellungen gleich (App.vue).
 */
const props = defineProps<{ updater: AppUpdater }>();

const notice = computed(() => updateNotice(props.updater));
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
</script>

<template>
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
</template>

<style scoped>
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
</style>
