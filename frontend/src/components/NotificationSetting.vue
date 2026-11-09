<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import type { ApiError } from '../api/client';
import { fetchNotifications, saveMailTopics, saveNotifications, type MailTopic } from '../api/auth';
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

/** Mail-Auswahl (P2.10): alle Themen oder nur die angehakten; erst „Auswahl speichern“ schreibt. */
const available = ref<readonly MailTopic[]>([]);
const savedSelection = ref<readonly string[] | null>(null);
const mode = ref<'all' | 'some'>('all');
const picked = ref<string[]>([]);
const selectionChanged = computed(() =>
    mode.value === 'all'
        ? savedSelection.value !== null
        : savedSelection.value === null ||
          [...savedSelection.value].sort().join() !== [...picked.value].sort().join(),
);
const selectionProblem = computed(() =>
    mode.value === 'some' && picked.value.length === 0
        ? 'Bitte mindestens ein Thema wählen oder die Mails oben ausschalten.'
        : null,
);

function takeTopics(data: {
    topics?: { selected: readonly string[] | null; available: readonly MailTopic[] };
}): void {
    available.value = data.topics?.available ?? [];
    savedSelection.value = data.topics?.selected ?? null;
    mode.value = savedSelection.value === null ? 'all' : 'some';
    picked.value = [...(savedSelection.value ?? [])];
}

const email = computed(() => session.value?.user.email ?? null);

async function load(): Promise<void> {
    busy.value = true;
    loadError.value = null;
    const result = await fetchNotifications();
    busy.value = false;
    if (result.ok) {
        enabled.value = result.data.deviceLog;
        takeTopics(result.data);
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
        takeTopics(result.data);
        message.value = result.data.deviceLog
            ? 'Gespeichert: Sie erhalten ab jetzt eine Mail bei jeder Änderung an der Ausrüstung.'
            : 'Gespeichert: Sie erhalten keine Mails zu Änderungen an der Ausrüstung mehr.';
    } else {
        saveError.value = result.error;
        input.checked = enabled.value === true;
    }
}

async function saveSelection(): Promise<void> {
    if (selectionProblem.value !== null || busy.value) {
        return;
    }
    busy.value = true;
    saveError.value = null;
    message.value = null;
    const result = await saveMailTopics(mode.value === 'all' ? null : picked.value);
    busy.value = false;
    if (result.ok) {
        takeTopics(result.data);
        message.value =
            mode.value === 'all'
                ? 'Gespeichert: Sie erhalten Mails zu allen Vorgängen.'
                : 'Gespeichert: Sie erhalten Mails nur zu den gewählten Vorgängen.';
    } else {
        saveError.value = result.error;
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
                <span class="notification-label">Mail bei jeder Änderung an der Ausrüstung</span>
                <span id="notification-hint" class="hint">
                    Umbuchen, Status, Wartungsdatum, Stammdaten und Import, dazu die Hinweise im
                    Protokoll<template v-if="allTenants === true"> – aus allen Städten</template
                    ><template v-if="email !== null"> – an {{ email }}</template
                    >. Eigene Änderungen sind ausgenommen.
                </span>
            </span>
        </label>
        <fieldset
            v-if="enabled === true && available.length > 0"
            class="topics"
            data-testid="mail-topics"
        >
            <legend class="notification-label">Mails zu diesen Vorgängen</legend>
            <label class="check">
                <input v-model="mode" type="radio" name="mail-mode" value="all" :disabled="busy" />
                <span>Alle Vorgänge</span>
            </label>
            <label class="check">
                <input v-model="mode" type="radio" name="mail-mode" value="some" :disabled="busy" />
                <span>Nur ausgewählte</span>
            </label>
            <div v-if="mode === 'some'" class="topic-list">
                <label v-for="topic in available" :key="topic.id" class="check notification">
                    <input
                        v-model="picked"
                        type="checkbox"
                        :value="topic.id"
                        :disabled="busy"
                        :data-testid="`topic-${topic.id}`"
                    />
                    <span class="notification-text">
                        <span class="notification-label">{{ topic.label }}</span>
                        <span class="hint">{{ topic.hint }}</span>
                    </span>
                </label>
            </div>
            <p v-if="selectionProblem !== null" class="field-error" role="alert">
                {{ selectionProblem }}
            </p>
            <button
                class="btn"
                type="button"
                data-testid="save-topics"
                :disabled="busy || !selectionChanged || selectionProblem !== null"
                @click="saveSelection"
            >
                Auswahl speichern
            </button>
        </fieldset>
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

.topics {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 12px 0 0;
    padding: 0;
    border: 0;
}

.topic-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-left: 24px;
}

.hint {
    color: var(--muted);
    font-size: var(--font-size-sm);
}
</style>
