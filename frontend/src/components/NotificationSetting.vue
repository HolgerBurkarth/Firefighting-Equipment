<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import type { ApiError } from '../api/client';
import { fetchNotifications, saveNotifications } from '../api/auth';
import { session } from '../auth/session';
import ErrorNotice from './ErrorNotice.vue';

/**
 * Benachrichtigung „Log“ des eigenen Kontos (GET/POST auth/notifications): Mail bei jedem Vorgang
 * der Geräte-Historie und zu jedem Hinweis, eigene ausgenommen. Wirkt sofort beim Umschalten;
 * scheitert das Speichern, bleibt der bisherige Stand. Super-Admins erhalten die Mails aller Städte
 * (`allTenants`, ohne Links: sie sehen keine Geräte).
 */
defineProps<{ allTenants?: boolean }>();
const enabled = ref<boolean | null>(null);
const busy = ref(false);
const loadError = shallowRef<ApiError | null>(null);
const saveError = shallowRef<ApiError | null>(null);
const message = ref<string | null>(null);

const email = computed(() => session.value?.user.email ?? null);

async function load(): Promise<void> {
    busy.value = true;
    loadError.value = null;
    const result = await fetchNotifications();
    busy.value = false;
    if (result.ok) {
        enabled.value = result.data.deviceLog;
    } else {
        loadError.value = result.error;
    }
}

onMounted(() => void load());

async function onChange(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const wanted = input.checked;
    busy.value = true;
    saveError.value = null;
    message.value = null;
    const result = await saveNotifications(wanted);
    busy.value = false;
    if (result.ok) {
        enabled.value = result.data.deviceLog;
        message.value = result.data.deviceLog
            ? 'Gespeichert: Sie erhalten ab jetzt eine Mail bei jeder Änderung an Geräten.'
            : 'Gespeichert: Sie erhalten keine Mails zu Änderungen an Geräten mehr.';
    } else {
        saveError.value = result.error;
        input.checked = enabled.value === true;
    }
}
</script>

<!-- Wie im Mockup „Einstellungen“: ein Schalter „Mail bei jeder Änderung an Geräten“. -->
<template>
    <section class="card" aria-labelledby="notification-heading">
        <h2 id="notification-heading">Benachrichtigung</h2>
        <ErrorNotice v-if="loadError !== null" :error="loadError" />
        <button v-if="loadError !== null" class="btn" type="button" :disabled="busy" @click="load">
            Erneut versuchen
        </button>
        <label v-else class="check notification">
            <input
                type="checkbox"
                :checked="enabled === true"
                :disabled="busy || enabled === null"
                aria-describedby="notification-hint"
                data-testid="device-log"
                @change="onChange"
            />
            <span class="notification-text">
                <span class="notification-label">Mail bei jeder Änderung an Geräten</span>
                <span id="notification-hint" class="hint">
                    Umbuchen, Status, Prüfdatum, Stammdaten und Import, dazu die Hinweise im
                    Protokoll<template v-if="allTenants === true"> – aus allen Städten</template
                    ><template v-if="email !== null"> – an {{ email }}</template
                    >. Eigene Änderungen sind ausgenommen.
                </span>
            </span>
        </label>
        <p v-if="message !== null" class="notice notice-success" role="status">{{ message }}</p>
        <ErrorNotice v-if="saveError !== null" :error="saveError" />
    </section>
</template>

<style scoped>
.notification {
    align-items: flex-start;
}

.notification input {
    margin-top: 2px;
}

.notification-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.notification-label {
    font-weight: var(--font-weight-semibold);
}

.hint {
    color: var(--muted);
    font-size: var(--font-size-sm);
}
</style>
