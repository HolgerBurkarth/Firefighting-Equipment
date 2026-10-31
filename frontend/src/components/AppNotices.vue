<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { errorMessage } from '../auth/errorMessages';
import { sessionCheckError, usingStoredSession } from '../auth/session';
import { offlineData, syncedAtText } from '../data/offline';
import { isOffline } from '../runtime/connectivity';
import { updateNotice, type AppUpdater } from '../runtime/appUpdate';
import { tenantState } from '../runtime/tenantState';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * Hinweise oben auf jeder Seite: Fehler der Stadt, offline bzw. gestörte Sitzungsprüfung, Updates
 * der App (siehe KONZEPT.md, "Updates der App"). In allen Darstellungen gleich (App.vue).
 */
const props = defineProps<{ updater: AppUpdater }>();

const notice = computed(() => updateNotice(props.updater));

/**
 * Grauer Streifen (KONZEPT.md, "Offline-Modus – Entscheidungen", 51): offline bzw. mit der
 * gespeicherten Sitzung, solange der Server nicht antwortet; mit dem Stand der lokalen Kopie.
 */
const offlineText = computed(() => {
    if (!isOffline.value && !usingStoredSession.value) {
        return null;
    }
    const head = isOffline.value ? 'Offline' : 'Keine Verbindung zum Server';
    const synced = offlineData.syncedAt;
    return synced === null
        ? `${head} – neuer Versuch läuft automatisch.`
        : `${head} – angezeigt wird der Stand vom ${syncedAtText(synced)}.`;
});
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

/**
 * Höhe des Update-Hinweises als CSS-Variable `--app-notice-height` (CSSOM, kein style-Attribut):
 * Bei offenem Fenster steht der Hinweis fest oben und das Fenster rückt darunter (base.css), damit
 * er auch am Handy und bei gescrollter Seite bedienbar bleibt.
 */
const NOTICE_HEIGHT = '--app-notice-height';
const passthrough = ref<HTMLElement | null>(null);
let observer: ResizeObserver | null = null;

watch(passthrough, (element) => {
    observer?.disconnect();
    observer = null;
    const root = document.documentElement;
    if (element === null) {
        root.style.removeProperty(NOTICE_HEIGHT);
        return;
    }
    observer = new ResizeObserver(() => {
        root.style.setProperty(NOTICE_HEIGHT, `${String(element.offsetHeight)}px`);
    });
    observer.observe(element);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    document.documentElement.style.removeProperty(NOTICE_HEIGHT);
});
</script>

<template>
    <p v-if="tenantState.error !== null" class="app-error" role="alert">
        {{ errorMessage(tenantState.error) }}
        <small>({{ tenantState.error.code }})</small>
    </p>

    <!--
        Sitzungsprüfung gestört ohne gespeicherte Sitzung: nie zur Anmeldung, erneut versuchen. Sonst
        offline (bzw. mit gespeicherter Sitzung) der graue Streifen mit dem Stand der Daten.
    -->
    <p
        v-if="sessionCheckError !== null && !usingStoredSession"
        class="app-error app-offline"
        role="status"
        data-testid="connection-notice"
    >
        Keine Verbindung zum Server, neuer Versuch läuft automatisch.
        <small>({{ sessionCheckError.code }})</small>
    </p>
    <p
        v-else-if="offlineText !== null"
        class="app-error app-offline"
        role="status"
        data-testid="offline-notice"
    >
        {{ offlineText }}
    </p>

    <!-- data-dialog-passthrough: auch bei offenem Fenster bedienbar (FormDialog, base.css). -->
    <div
        v-if="updater.reloadNeeded.value"
        ref="passthrough"
        class="app-update"
        role="status"
        data-testid="update-reload-needed"
        data-dialog-passthrough
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
    <div
        v-else-if="notice !== null"
        ref="passthrough"
        class="app-update"
        role="status"
        data-testid="update-notice"
        data-dialog-passthrough
    >
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
