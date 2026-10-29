<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import type { ApiError } from '../api/client';
import { endSession, fetchSessions, logoutOtherSessions, type AccountSession } from '../api/auth';
import {
    endedSessionsText,
    sessionLabel,
    sessionTimeText,
    sortSessions,
} from '../auth/sessionList';
import { useNow } from '../runtime/now';
import ConfirmDialog from './ConfirmDialog.vue';
import ErrorNotice from './ErrorNotice.vue';

const sessions = shallowRef<readonly AccountSession[] | null>(null);
const loading = ref(false);
const loadError = shallowRef<ApiError | null>(null);
/** Fehler beim Abmelden eines Geräts bzw. aller anderen. */
const actionError = shallowRef<ApiError | null>(null);
const busyId = ref<number | null>(null);
const busyOthers = ref(false);
const confirmOpen = ref(false);
const message = ref<string | null>(null);
const now = useNow();

const sorted = computed(() => (sessions.value === null ? [] : sortSessions(sessions.value)));
const hasOthers = computed(() => sorted.value.some((session) => !session.current));

let sequence = 0;

/** Neu laden; ältere Antworten werden verworfen. Bei einem Fehler bleibt die bisherige Liste. */
async function load(): Promise<void> {
    const own = ++sequence;
    loading.value = true;
    const result = await fetchSessions();
    if (own !== sequence) {
        return;
    }
    loading.value = false;
    if (result.ok) {
        sessions.value = result.data;
        loadError.value = null;
    } else {
        loadError.value = result.error;
    }
}

defineExpose({ reload: load });

onMounted(() => void load());

async function onEnd(session: AccountSession): Promise<void> {
    busyId.value = session.id;
    actionError.value = null;
    message.value = null;
    const result = await endSession(session.id);
    busyId.value = null;
    // 404: schon beendet (abgelaufen oder auf dem anderen Gerät abgemeldet), also auch erledigt.
    if (result.ok || result.error.code === 'not_found') {
        message.value = `${sessionLabel(session)} wurde abgemeldet.`;
    } else {
        actionError.value = result.error;
    }
    await load();
}

async function onEndOthers(): Promise<void> {
    busyOthers.value = true;
    actionError.value = null;
    message.value = null;
    const result = await logoutOtherSessions();
    busyOthers.value = false;
    if (result.ok) {
        message.value = endedSessionsText(result.data.endedSessions);
    } else {
        actionError.value = result.error;
    }
    await load();
}
</script>

<!-- Angemeldete Geräte: einzeln abmelden oder alle anderen (mit Rückfrage). -->
<template>
    <section class="card" aria-labelledby="sessions-heading">
        <div class="heading">
            <h2 id="sessions-heading">Angemeldete Geräte</h2>
            <p class="hint">Auf diesen Geräten sind Sie gerade angemeldet.</p>
        </div>

        <p
            v-if="message !== null"
            class="notice notice-success"
            role="status"
            data-testid="sessions-message"
        >
            {{ message }}
        </p>
        <ErrorNotice v-if="actionError !== null" :error="actionError" />

        <template v-if="sessions === null">
            <p v-if="loadError === null" class="hint" role="status">Geräte werden geladen …</p>
            <ErrorNotice v-else :error="loadError">
                <button class="btn" type="button" :disabled="loading" @click="load">
                    Erneut versuchen
                </button>
            </ErrorNotice>
        </template>

        <template v-else>
            <p
                v-if="loadError !== null"
                class="hint"
                role="status"
                data-testid="sessions-refresh-error"
            >
                Die Liste konnte nicht aufgefrischt werden, sie zeigt den letzten Stand.
                <small>({{ loadError.code }})</small>
            </p>
            <ul class="sessions" data-testid="session-list">
                <li
                    v-for="session in sorted"
                    :key="session.id"
                    class="session"
                    data-testid="session"
                >
                    <span class="session-text">
                        <span class="session-label">
                            {{ sessionLabel(session) }}
                            <span v-if="session.current" class="badge current">Dieses Gerät</span>
                        </span>
                        <span class="session-time" data-testid="session-time">{{
                            sessionTimeText(session, now)
                        }}</span>
                    </span>
                    <button
                        v-if="!session.current"
                        class="btn"
                        type="button"
                        :aria-label="`${sessionLabel(session)} abmelden`"
                        :disabled="busyId !== null || busyOthers"
                        @click="onEnd(session)"
                    >
                        Abmelden
                    </button>
                </li>
            </ul>
            <button
                v-if="hasOthers"
                class="btn"
                type="button"
                :disabled="busyId !== null || busyOthers"
                @click="confirmOpen = true"
            >
                Alle anderen Geräte abmelden
            </button>
        </template>

        <ConfirmDialog
            v-model:open="confirmOpen"
            title="Alle anderen Geräte abmelden?"
            description="Auf allen anderen Geräten müssen Sie sich danach neu anmelden. Dieses Gerät bleibt angemeldet."
            confirm-label="Abmelden"
            @confirm="onEndOthers"
        />
    </section>
</template>

<style scoped>
.heading {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.hint {
    margin: 0;
    color: var(--muted);
    font-size: var(--font-size-md);
}

.sessions {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
}

.session {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 12px;
    padding: 10px 0;
    border-top: 1px solid var(--border-subtle);
}

.session:first-child {
    border-top: 0;
}

.session-text {
    display: flex;
    flex: 1 1 200px;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.session-label {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 8px;
    font-weight: var(--font-weight-semibold);
}

.current {
    background: var(--selected-bg);
    color: var(--text);
}

.session-time {
    color: var(--muted);
    font-size: var(--font-size-sm);
}
</style>
